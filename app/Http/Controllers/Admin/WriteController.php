<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CollectionKind;
use App\Enums\HeadingStyle;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WritingDay;
use App\Support\ChaptersAdmin;
use App\Support\CommentsInbox;
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
            'publish' => WritePage::state($item),
            'chapters' => $multi ? ChaptersAdmin::rows($work) : [],
            'plan' => $work->kind === WorkKind::Poem ? null : PlanPage::props($work),
            'sweep' => ['names' => SweepNames::list($work), 'mark' => $work->mark_names],
            'notes' => CommentsInbox::itemNotes($item),
        ]);
    }

    /**
     * Autosave: the page, its title, part description, POV, heading quote or soundtrack — whichever changed.
     */
    public function save(Request $request, Work $work, WorkItem $item): JsonResponse
    {
        abort_unless($item->work_id === $work->id, 404);

        $data = $request->validate([
            'body' => ['sometimes', 'array'],
            'body.type' => ['required_with:body', Rule::in(['doc'])],
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

        DB::transaction(function () use ($data, $raw, $work, $item, $label): void {
            if (array_key_exists('body', $data) && is_array($raw)) {
                $body = Prose::clean($raw) ?? Prose::empty();
                $before = (int) $item->draft_words;
                $words = Prose::words($body);

                $item->forceFill([
                    'draft_body' => $body,
                    'draft_text' => Prose::text($body),
                    'draft_words' => $words,
                    'draft_revision' => $item->draft_revision + 1,
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
        });

        return response()->json([
            'savedAt' => $item->draft_saved_at->toIso8601String(),
            'words' => (int) $item->draft_words,
            'soundtrack' => $item->soundtrack_url ? ['url' => $item->soundtrack_url, 'label' => $item->soundtrack_label ?: 'Soundtrack'] : null,
        ]);
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
