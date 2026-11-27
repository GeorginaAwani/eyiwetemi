<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CollectionKind;
use App\Enums\HeadingStyle;
use App\Enums\VersionLabel;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WritingDay;
use App\Support\ChaptersAdmin;
use App\Support\CommentsInbox;
use App\Support\DeviceKind;
use App\Support\ImageStore;
use App\Support\PlanPage;
use App\Support\Prose;
use App\Support\SweepNames;
use App\Support\WorkPresenter;
use App\Support\WritePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The writing screen: a chapter, part page or single piece as a page of the book. Everything saves as she types,
 * into the draft; readers only see what she publishes.
 */
class WriteController extends Controller
{
    /** Pictures in essays and stories: no wider or taller than this. */
    private const IMAGE_SIDE = 1600;

    public function show(Work $work, WorkItem $item): Response|RedirectResponse
    {
        abort_unless($item->work_id === $work->id, 404);

        /* a piece a collection borrows is written on its own page */
        if ($item->piece_work_id && ($piece = Work::query()->find($item->piece_work_id))) {
            $own = $piece->items()->first();

            return $own ? redirect('/works/'.$piece->slug.'/write/'.$own->id) : redirect('/works/'.$piece->slug);
        }

        $multi = in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true);
        $same = WorkItem::query()->where('work_id', $work->id)->where('position', '<=', $item->position);

        return Inertia::render('Admin/Write', [
            'work' => [
                'slug' => $work->slug,
                'title' => $work->title,
                'kind' => self::kind($work),
                'multi' => $multi,
                'collection' => $work->kind === WorkKind::Collection,
                'href' => '/works/'.$work->slug.'?tab='.($multi ? 'chapters' : 'settings'),
                'headingStyle' => ($work->heading_style ?? HeadingStyle::ChapterNumber)->value,
                'showChapterTitles' => $work->show_chapter_titles,
                'partWord' => $work->part_word ?? 'Part',
                'partStyle' => ($work->part_style ?? HeadingStyle::ChapterWord)->value,
                'showPartNumbers' => $work->show_part_numbers,
                'sceneBreak' => $work->scene_break->value,
            ],
            'item' => [
                'id' => $item->id,
                'type' => $item->type->value,
                'number' => $item->type === WorkItemType::Part
                    ? (clone $same)->where('type', WorkItemType::Part)->count()
                    : (clone $same)->where('type', '!=', WorkItemType::Part)->count(),
                'title' => $item->title ?? '',
                'description' => $item->description ?? '',
                'body' => $item->draft_body ?? $item->live_body ?? Prose::empty(),
                'status' => $item->status->value,
                'pov' => $item->pov_character_id,
                'soundtrack' => $item->soundtrack_url ? ['url' => $item->soundtrack_url, 'label' => $item->soundtrack_label ?: 'Soundtrack'] : null,
                'quote' => $item->epigraph !== null || $item->epigraph_by !== null ? ['q' => $item->epigraph ?? '', 'by' => $item->epigraph_by ?? ''] : null,
            ],
            'characters' => $work->kind === WorkKind::Novel
                ? $work->characters()->orderBy('position')->get(['id', 'name'])->map(fn ($c): array => ['id' => $c->id, 'name' => $c->name])->all()
                : [],
            'images' => self::images($item->draft_body ?? $item->live_body),
            'draft' => [
                'revision' => (int) $item->draft_revision,
                'device' => $item->draft_device,
                'savedAt' => $item->draft_saved_at?->toIso8601String(),
            ],
            'publish' => WritePage::state($item),
            'chapters' => $multi ? ChaptersAdmin::rows($work) : [],
            'plan' => $work->kind === WorkKind::Poem ? null : PlanPage::props($work),
            'sweep' => ['names' => SweepNames::list($work), 'mark' => $work->mark_names],
            'notes' => CommentsInbox::itemNotes($item),
        ]);
    }

    /**
     * Autosave: the page, its title, part description, POV, heading quote or soundtrack — whichever changed.
     *
     * A page sent with `base` (the draft revision it was written from) is refused with a 409 "Two drafts" when the
     * draft has moved on since, and nothing is saved.
     */
    public function save(Request $request, Work $work, WorkItem $item): JsonResponse
    {
        abort_unless($item->work_id === $work->id, 404);

        $data = $request->validate([
            'body' => ['sometimes', 'array'],
            'body.type' => ['required_with:body', Rule::in(['doc'])],
            'base' => ['sometimes', 'integer', 'min:0'],
            'title' => ['sometimes', 'nullable', 'string', 'max:300'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'pov' => ['sometimes', 'nullable', 'integer', Rule::exists('characters', 'id')->where('work_id', $work->id)],
            'quote' => ['sometimes', 'nullable', 'array'],
            'quote.q' => ['nullable', 'string', 'max:2000'],
            'quote.by' => ['nullable', 'string', 'max:300'],
            'soundtrack' => ['sometimes', 'nullable', 'string', 'max:500', 'regex:#^https://(www\.|m\.|music\.)?(youtube\.com|youtu\.be|open\.spotify\.com)/#i'],
        ], ['soundtrack.regex' => 'Paste a YouTube or Spotify link.']);

        if (strlen((string) $request->getContent()) > 3_000_000) {
            abort(413);
        }

        $label = array_key_exists('soundtrack', $data) && $data['soundtrack'] ? self::soundtrackLabel($data['soundtrack']) : null;

        /* validated() keeps only the keys named in the rules; the page itself comes whole and is cleaned here */
        $raw = $request->input('body');

        $stale = DB::transaction(function () use ($data, $raw, $work, &$item, $label): bool {
            /* read under a lock, so another save can't land between the revision check and this write */
            $item = WorkItem::query()->whereKey($item->id)->lockForUpdate()->sole();

            $posted = array_key_exists('body', $data) && is_array($raw) ? (Prose::clean($raw) ?? Prose::empty()) : null;

            /* a stale base whose page is already the draft (an earlier keepalive landed) is not a conflict */
            if ($posted !== null && array_key_exists('base', $data) && (int) $data['base'] !== (int) $item->draft_revision && $posted != $item->draft_body) {
                return true;
            }

            if ($posted !== null) {
                $body = $posted;
                $before = (int) $item->draft_words;
                $words = Prose::words($body);

                $item->forceFill([
                    'draft_body' => $body,
                    'draft_text' => Prose::text($body),
                    'draft_words' => $words,
                    'draft_revision' => $item->draft_revision + 1,
                    'draft_device' => DeviceKind::current(),
                ]);

                self::countDay($work, $words - $before);
            }

            foreach (['title', 'description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $value = trim((string) preg_replace('/\s+/u', ' ', (string) $data[$field]));
                    $item->{$field} = $value === '' ? null : $value;
                }
            }

            if (array_key_exists('pov', $data)) {
                $item->pov_character_id = $data['pov'];
            }

            if (array_key_exists('quote', $data)) {
                $quote = $data['quote'];
                $item->epigraph = $quote === null ? null : trim((string) ($quote['q'] ?? ''));
                $item->epigraph_by = $quote === null ? null : trim((string) ($quote['by'] ?? ''));
            }

            if (array_key_exists('soundtrack', $data)) {
                $item->soundtrack_url = $data['soundtrack'] ?: null;
                $item->soundtrack_label = $data['soundtrack'] ? $label : null;
            }

            $item->draft_saved_at = now();
            $item->save();

            return false;
        });

        if ($stale) {
            return self::twoDraftsResponse($item);
        }

        return response()->json([
            'savedAt' => $item->draft_saved_at->toIso8601String(),
            'words' => (int) $item->draft_words,
            'revision' => (int) $item->draft_revision,
            'soundtrack' => $item->soundtrack_url ? ['url' => $item->soundtrack_url, 'label' => $item->soundtrack_label ?: 'Soundtrack'] : null,
        ]);
    }

    /**
     * Two drafts: this device's page and the draft on the server both changed from the same revision. "this" keeps this
     * device's page as the draft and the server's draft goes into Versions; "other" keeps the server's draft and this
     * device's page goes into Versions.
     */
    public function twoDrafts(Request $request, Work $work, WorkItem $item): JsonResponse
    {
        abort_unless($item->work_id === $work->id, 404);

        $request->validate([
            'keep' => ['required', Rule::in(['this', 'other'])],
            'body' => ['required', 'array'],
            'body.type' => ['required', Rule::in(['doc'])],
            'revision' => ['required', 'integer', 'min:0'],
        ]);

        if (strlen((string) $request->getContent()) > 3_000_000) {
            abort(413);
        }

        $keep = $request->input('keep');
        $revision = (int) $request->input('revision');
        /* validated() keeps only the keys named in the rules; this device's page comes whole and is cleaned here */
        $body = Prose::clean($request->input('body')) ?? Prose::empty();
        $device = DeviceKind::current();
        $by = $request->user()?->id;

        $stale = DB::transaction(function () use ($work, &$item, $keep, $revision, $body, $device, $by): bool {
            $item = WorkItem::query()->whereKey($item->id)->lockForUpdate()->sole();

            if ($revision !== (int) $item->draft_revision) {
                return true;
            }

            if ($keep === 'this') {
                $current = $item->draft_body ?? $item->live_body ?? Prose::empty();

                $item->versions()->create([
                    'label' => VersionLabel::DraftFromDevice,
                    'device' => $item->draft_device,
                    'body' => $current,
                    'words' => Prose::words($current),
                    ...self::changeSinceLastVersion($item, $current),
                    'created_by' => $by,
                ]);

                $before = (int) $item->draft_words;
                $words = Prose::words($body);

                $item->forceFill([
                    'draft_body' => $body,
                    'draft_text' => Prose::text($body),
                    'draft_words' => $words,
                    'draft_revision' => $item->draft_revision + 1,
                    'draft_device' => $device,
                    'draft_saved_at' => now(),
                ]);

                self::countDay($work, $words - $before);
            } else {
                $item->versions()->create([
                    'label' => VersionLabel::DraftFromDevice,
                    'device' => $device,
                    'body' => $body,
                    'words' => Prose::words($body),
                    ...self::changeSinceLastVersion($item, $body),
                    'created_by' => $by,
                ]);

                $item->forceFill([
                    'draft_revision' => $item->draft_revision + 1,
                    'draft_saved_at' => now(),
                ]);
            }

            $item->save();

            return false;
        });

        if ($stale) {
            return self::twoDraftsResponse($item);
        }

        return response()->json([
            'revision' => (int) $item->draft_revision,
            'body' => $item->draft_body ?? $item->live_body ?? Prose::empty(),
            'words' => (int) $item->draft_words,
            'savedAt' => $item->draft_saved_at?->toIso8601String(),
            'publish' => WritePage::state($item->fresh()),
        ]);
    }

    /**
     * The 409 for "Two drafts": the draft the server holds now, for the screen to set beside this device's page.
     */
    private static function twoDraftsResponse(WorkItem $item): JsonResponse
    {
        return response()->json([
            'message' => 'Two drafts',
            'revision' => (int) $item->draft_revision,
            'other' => [
                'body' => $item->draft_body ?? $item->live_body ?? Prose::empty(),
                'words' => (int) $item->draft_words,
                'savedAt' => $item->draft_saved_at?->toIso8601String(),
                'device' => $item->draft_device,
            ],
        ], 409);
    }

    /**
     * A picture in an essay or story.
     */
    public function image(Request $request, Work $work, WorkItem $item): JsonResponse
    {
        abort_unless($item->work_id === $work->id, 404);

        $request->validate(
            ['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:15360']],
            ['image.mimes' => 'That isn’t a picture we can use. Try a JPG, PNG or WebP.', 'image.max' => 'That picture is too big. Try one under 15 MB.'],
        );

        $media = ImageStore::store($request->file('image'), 'images', self::IMAGE_SIDE, $request->user(), 'image');

        return response()->json(['mediaId' => $media->id, 'src' => WorkPresenter::mediaUrl($media->disk, $media->path)], 201);
    }

    /**
     * novel, story, poem or essay: what the page is written as (a collection's pieces take the collection's kind).
     */
    private static function kind(Work $work): string
    {
        return match ($work->kind) {
            WorkKind::Collection => ($work->collection_kind ?? CollectionKind::Story)->value,
            default => $work->kind->value,
        };
    }

    /**
     * Words added and cut since the newest version, as publishing counts them.
     *
     * @param  array<string, mixed>  $body
     * @return array{words_added: int, words_cut: int}
     */
    private static function changeSinceLastVersion(WorkItem $item, array $body): array
    {
        $previous = $item->versions()->first();
        $change = Prose::wordChange($previous ? Prose::text($previous->body) : '', Prose::text($body));

        return ['words_added' => $change['added'], 'words_cut' => $change['cut']];
    }

    /**
     * The words she wrote today (WAT), for the desk's "Words written".
     */
    private static function countDay(Work $work, int $change): void
    {
        if ($change === 0) {
            return;
        }

        $day = WritingDay::query()->firstOrCreate(
            ['day' => now((string) config('eyiwetemi.timezone'))->toDateString(), 'work_id' => $work->id],
            ['words_added' => 0, 'words_cut' => 0],
        );

        $change > 0 ? $day->increment('words_added', $change) : $day->increment('words_cut', -$change);
    }

    /**
     * The title of a YouTube video or Spotify track, asked of the site itself; just "YouTube" or "Spotify" when it doesn't say.
     */
    private static function soundtrackLabel(string $url): string
    {
        $spotify = str_contains(strtolower($url), 'spotify.com');
        $service = $spotify ? 'Spotify' : 'YouTube';
        $endpoint = $spotify ? 'https://open.spotify.com/oembed' : 'https://www.youtube.com/oembed';

        try {
            $answer = Http::timeout(4)->acceptJson()->get($endpoint, ['url' => $url, 'format' => 'json']);
        } catch (\Throwable) {
            return $service;
        }

        $title = trim((string) $answer->json('title'));
        $by = trim((string) $answer->json('author_name'));

        if (! $answer->successful() || $title === '') {
            return $service;
        }

        return mb_substr($spotify || $by === '' ? $title : "{$title} · {$by}", 0, 250);
    }

    /**
     * Addresses of the pictures in a page, by media id.
     *
     * @param  array<string, mixed>|null  $doc
     * @return array<int, string>
     */
    private static function images(?array $doc): array
    {
        $ids = [];
        foreach ($doc['content'] ?? [] as $node) {
            if (($node['type'] ?? null) === 'image' && isset($node['attrs']['mediaId'])) {
                $ids[] = (int) $node['attrs']['mediaId'];
            }
        }

        return Media::query()->whereIn('id', $ids)->get()
            ->mapWithKeys(fn ($m): array => [$m->id => WorkPresenter::mediaUrl($m->disk, $m->path)])->all();
    }
}
