<?php

namespace App\Support;

use App\Enums\PublishStatus;
use App\Models\Follow;
use App\Models\Note;
use App\Models\Work;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The admin Notes page's data: every note as she is writing it (its draft), what is out and whether the draft differs,
 * the hearts it has had since the desk last looked, and the works she can tag it with. Every time is West Africa Time.
 */
class NotesAdmin
{
    /**
     * Everything the Notes page draws: the notes, the works, the note open from the desk and the work filter, and now.
     *
     * @return array{notes: list<array<string, mixed>>, works: list<array{slug: string, title: string, kind: string, public: bool, followers: int}>, open: int|null, w: string, now: string}
     */
    public static function props(?int $open, string $work): array
    {
        $since = DeskPage::heartsSince();

        $notes = Note::query()
            ->with(['draftMedia:id,disk,path', 'draftWork:id,slug'])
            ->withCount(self::heartCounts($since))
            ->orderByDesc('id')
            ->get();

        return [
            'notes' => $notes->map(fn (Note $note): array => self::note($note, $since))->values()->all(),
            'works' => self::works(),
            'open' => $open,
            'w' => $work,
            'now' => now(self::zone())->format('Y-m-d\TH:i'),
        ];
    }

    /**
     * One note as the page reads it. Loads the picture, the work and the hearts when the caller has not.
     *
     * @return array<string, mixed>
     */
    public static function note(Note $note, ?CarbonInterface $heartsSince = null): array
    {
        $note->loadMissing(['draftMedia:id,disk,path', 'draftWork:id,slug']);

        if (! array_key_exists('new_likes_count', $note->getAttributes())) {
            $note->loadCount(self::heartCounts($heartsSince ?? DeskPage::heartsSince()));
        }

        $state = match ($note->status) {
            PublishStatus::Published => 'live',
            PublishStatus::Scheduled => 'sch',
            default => 'draft',
        };
        $zone = self::zone();
        $photo = $note->draftMedia;

        return [
            'id' => $note->id,
            'state' => $state,
            'changed' => $state === 'live' && self::differs($note),
            'day' => $state === 'live' && $note->published_at !== null ? $note->published_at->copy()->setTimezone($zone)->format('Y-m-d') : null,
            'at' => $state === 'sch' && $note->scheduled_at !== null ? $note->scheduled_at->copy()->setTimezone($zone)->format('Y-m-d\TH:i') : null,
            'edited' => $note->updated_at->copy()->utc()->toIso8601String(),
            'justNow' => NotePublisher::canUndo($note),
            'body' => $note->draft_body ?? Prose::empty(),
            'text' => (string) $note->draft_text,
            'work' => $note->draftWork?->slug,
            'photo' => $photo ? ['id' => $photo->id, 'src' => WorkPresenter::mediaUrl($photo->disk, $photo->path)] : null,
            'quiet' => ! $note->notify_followers,
            'likes' => (int) $note->likes_count,
            'newLikes' => (int) $note->new_likes_count,
        ];
    }

    /**
     * Cleans a note as she typed it: only paragraphs of text and line breaks, with the editor's safe marks. Anything else
     * is dropped, and a note left with no paragraphs is an empty one.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function cleanBody(array $raw): array
    {
        $paragraphs = [];

        foreach ((Prose::clean($raw) ?? [])['content'] ?? [] as $node) {
            if (($node['type'] ?? null) !== 'paragraph') {
                continue;
            }

            $children = array_values(array_filter(
                $node['content'] ?? [],
                fn (array $child): bool => in_array($child['type'], ['text', 'hardBreak'], true),
            ));
            unset($node['content']);

            if ($children !== []) {
                $node['content'] = $children;
            }

            $paragraphs[] = $node;
        }

        return $paragraphs === [] ? Prose::empty() : ['type' => 'doc', 'content' => $paragraphs];
    }

    /**
     * The draft is not what is out: its words, its picture or its work has changed since it went out.
     */
    private static function differs(Note $note): bool
    {
        return $note->draft_body != $note->live_body
            || $note->draft_media_id != $note->media_id
            || $note->draft_work_id != $note->work_id;
    }

    /**
     * Every work, by title: whether readers can see it, and how many follow it.
     *
     * @return list<array{slug: string, title: string, kind: string, public: bool, followers: int}>
     */
    private static function works(): array
    {
        /** @var Collection<int, int> $listed */
        $listed = Work::query()->listed()->pluck('id')->flip();

        $followers = Follow::query()
            ->where('followable_type', (new Work)->getMorphClass())
            ->selectRaw('followable_id, count(*) as total')
            ->groupBy('followable_id')
            ->pluck('total', 'followable_id');

        return Work::query()->orderBy('title')->get(['id', 'title', 'slug', 'kind'])
            ->map(fn (Work $work): array => [
                'slug' => $work->slug,
                'title' => $work->title,
                'kind' => $work->kind->value,
                'public' => $listed->has($work->id),
                'followers' => (int) ($followers[$work->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * The count of hearts a note has, and the count it got since the given time (for withCount and loadCount).
     *
     * @return array<int|string, \Closure|string>
     */
    private static function heartCounts(CarbonInterface $since): array
    {
        return [
            'likes',
            'likes as new_likes_count' => fn (Builder $likes): Builder => $likes->where('created_at', '>', $since),
        ];
    }

    private static function zone(): string
    {
        return (string) config('eyiwetemi.timezone');
    }
}
