<?php

namespace App\Support;

use App\Enums\PublishStatus;
use App\Enums\SitePageKey;
use App\Enums\VersionLabel;
use App\Models\Note;
use App\Models\SitePage;
use App\Models\User;
use App\Models\Version;
use App\Notifications\SiteChanged;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What happens when she publishes a site page: the draft becomes what readers see, with a version of it. For Privacy and Terms
 * a change that matters also sets the line on the page, writes a draft note and tells readers. A publish can be taken back
 * for two minutes; a restored version goes back into the draft.
 */
class SitePublisher
{
    /** How long a publish can be taken back. */
    public const UNDO_MINUTES = 2;

    /**
     * Publishes the draft. Null when the draft is what is already live.
     *
     * @return array{noteId: int|null}|null
     *
     * @throws ValidationException when Privacy or Terms has no sections
     */
    public static function publish(SitePageKey $key, User $author, bool $matters, ?string $line): ?array
    {
        return DB::transaction(function () use ($key, $author, $matters, $line): ?array {
            $page = SitePage::query()->firstOrCreate(['key' => $key], ['draft' => SitePageDraft::empty($key)]);
            $draft = SitePageDraft::complete($key, $page->draft ?? SitePageDraft::empty($key));
            $previous = is_array($page->live) ? SitePageDraft::complete($key, $page->live) : null;
            $contents = in_array($key, [SitePageKey::Privacy, SitePageKey::Terms], true);
            $matters = $contents && $matters;

            if ($contents && $draft['sections'] === []) {
                throw ValidationException::withMessages(['sections' => 'Add a section before publishing.']);
            }

            if ($previous !== null && $previous == $draft) {
                return null;
            }

            $now = now();
            $live = $draft;

            if ($contents) {
                $today = $now->copy()->setTimezone((string) config('eyiwetemi.timezone'))->format('Y-m-d');
                $live['updated_on'] = $today;

                if ($matters) {
                    $ids = self::changedSectionIds($previous['sections'] ?? [], $live['sections']);
                    $line = self::lineFor($line, $live['sections'], $ids);
                    $live['change'] = ['on' => $today, 'line' => $line, 'ids' => $ids];
                }
            }

            $undo = [
                'live' => $previous,
                'published_at' => $page->published_at?->toIso8601String(),
                'draft_updated_on' => $draft['updated_on'] ?? null,
                'draft_change' => $draft['change'] ?? null,
            ];

            $change = SitePages::wordChange(SitePages::words($key, $previous ?? []), SitePages::words($key, $live));

            $page->forceFill(['live' => $live, 'draft' => $live, 'published_at' => $now])->save();

            $version = $page->versions()->create([
                'label' => VersionLabel::Published,
                'body' => [...$live, '_meta' => ['matters' => $matters, 'line' => $matters ? $line : null]],
                'words' => count(SitePages::words($key, $live)),
                'words_added' => $change['added'],
                'words_cut' => $change['cut'],
                'created_by' => $author->id,
            ]);

            $noteId = null;

            if ($matters) {
                $noteId = self::noteFor($key, $line)->id;
                Notification::send(
                    User::query()->whereKeyNot($author->getKey())->get(),
                    new SiteChanged($key->value, $line),
                );
            }

            Cache::put(self::undoKey($key), [
                ...$undo,
                'at' => $now->copy()->utc()->toDateTimeString(),
                'version_id' => $version->id,
                'note_id' => $noteId,
            ], now()->addMinutes(self::UNDO_MINUTES));

            return ['noteId' => $noteId];
        });
    }

    /**
     * Takes a publish back: what was live before, its publish time and the draft's date and change, the version it made,
     * the note it wrote, and the notices it sent.
     *
     * @param  array<string, mixed>  $undo  what publish() remembered
     */
    public static function undo(SitePageKey $key, array $undo): void
    {
        DB::transaction(function () use ($key, $undo): void {
            $page = SitePage::query()->where('key', $key)->firstOrFail();
            $draft = $page->draft ?? [];

            if (in_array($key, [SitePageKey::Privacy, SitePageKey::Terms], true)) {
                $draft['updated_on'] = $undo['draft_updated_on'];
                $draft['change'] = $undo['draft_change'];
            }

            $page->forceFill([
                'live' => $undo['live'],
                'draft' => $draft,
                'published_at' => $undo['published_at'] !== null ? Carbon::parse($undo['published_at']) : null,
            ])->save();

            $page->versions()->reorder()->whereKey($undo['version_id'])->delete();

            if ($undo['note_id'] !== null) {
                Note::query()->whereKey($undo['note_id'])->where('status', PublishStatus::Draft)->delete();
            }

            DB::table('notifications')
                ->where('type', SiteChanged::class)
                ->whereRaw("(data::jsonb)->>'page' = ?", [$key->value])
                ->where('created_at', '>=', $undo['at'])
                ->delete();
        });

        Cache::forget(self::undoKey($key));
    }

    /**
     * Puts a version's text back into the draft, without its meta.
     */
    public static function restore(SitePage $page, Version $version): void
    {
        $page->forceFill([
            'draft' => Arr::except($version->body, ['_meta']),
            'draft_saved_at' => now(),
        ])->save();
    }

    /**
     * The cache key a publish is remembered under for undo.
     */
    public static function undoKey(SitePageKey $key): string
    {
        return 'site.undo.'.$key->value;
    }

    /**
     * The sections that are new, or whose heading or text differs from what was live.
     *
     * @param  list<array<string, mixed>>  $previous
     * @param  list<array<string, mixed>>  $sections
     * @return list<string>
     */
    private static function changedSectionIds(array $previous, array $sections): array
    {
        $before = collect($previous)->keyBy('id');

        return collect($sections)
            ->filter(fn (array $section): bool => ! $before->has($section['id'])
                || $before->get($section['id'])['heading'] !== $section['heading']
                || $before->get($section['id'])['html'] !== $section['html'])
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * The line readers see: what she wrote, or "Changes to A, B and C." from the headings of the sections that changed.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  list<string>  $ids
     */
    private static function lineFor(?string $line, array $sections, array $ids): string
    {
        $typed = trim((string) $line);

        if ($typed !== '') {
            return $typed;
        }

        $headings = collect($sections)->whereIn('id', $ids)->pluck('heading')->values();

        if ($headings->isEmpty()) {
            return 'Changes to this page.';
        }

        $last = $headings->pop();
        $names = $headings->isEmpty() ? $last : $headings->implode(', ').' and '.$last;

        return Str::limit('Changes to '.$names.'.', 120, '…');
    }

    /**
     * The draft note that tells her followers' readers what changed: "I’ve changed my Terms. <line>".
     */
    private static function noteFor(SitePageKey $key, string $line): Note
    {
        $label = $key === SitePageKey::Terms ? 'Terms' : 'Privacy page';
        $body = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['id' => Prose::newId()],
                'content' => [
                    ['type' => 'text', 'text' => 'I’ve changed my '],
                    ['type' => 'text', 'text' => $label, 'marks' => [['type' => 'link', 'attrs' => ['href' => '/'.$key->value]]]],
                    ['type' => 'text', 'text' => '. '.$line],
                ],
            ]],
        ];

        return Note::query()->create([
            'status' => PublishStatus::Draft,
            'draft_body' => $body,
            'draft_text' => Prose::text($body),
            'notify_followers' => true,
        ]);
    }
}
