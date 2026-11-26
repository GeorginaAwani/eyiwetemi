<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CollectionKind;
use App\Enums\HeadingStyle;
use App\Enums\PublishStatus;
use App\Enums\Visibility;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWorkRequest;
use App\Http\Requests\Admin\UpdateWorkRequest;
use App\Models\Genre;
use App\Models\Series;
use App\Models\SlugHistory;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\ChaptersAdmin;
use App\Support\PlanPage;
use App\Support\WorksAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Her works: the list, a work's page (Settings save as she goes), New work and Delete.
 */
class WorkController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Works', ['works' => WorksAdmin::rows()]);
    }

    public function show(Request $request, Work $work): Response
    {
        $tab = in_array($request->query('tab'), ['chapters', 'plan', 'settings'], true) ? $request->query('tab') : 'settings';

        return Inertia::render('Admin/Work', [
            'tab' => $tab,
            'work' => WorksAdmin::settings($work),
            'choices' => WorksAdmin::choices($work),
            'contents' => in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true) ? [
                'rows' => ChaptersAdmin::rows($work),
                'opening' => $work->kind === WorkKind::Novel && ($work->opening_quote || $work->opening_quote_by)
                    ? ['q' => $work->opening_quote ?? '', 'by' => $work->opening_quote_by ?? ''] : null,
                'pool' => $work->kind === WorkKind::Collection ? self::pool($work) : [],
            ] : null,
            'plan' => $work->kind === WorkKind::Poem ? null : PlanPage::props($work),
        ]);
    }

    /**
     * Poems, stories or essays (the collection's kind) that sit in no collection yet: what Add can take in.
     *
     * @return list<array{id: int, title: string, meta: string}>
     */
    private static function pool(Work $work): array
    {
        $rows = collect(WorksAdmin::rows())->keyBy('slug');

        return Work::query()
            ->where('kind', $work->collection_kind?->value ?? 'story')
            ->whereNull('collection_id')
            ->orderBy('title')
            ->get(['id', 'slug', 'title'])
            ->map(fn (Work $w): array => ['id' => $w->id, 'title' => $w->title, 'meta' => trim(explode(' · ', $rows[$w->slug]['length'] ?? '')[0].' · '.($rows[$w->slug]['edited'] ?? ''), ' ·')])
            ->all();
    }

    /**
     * Is this title already one of hers? The New work dialog asks as she types.
     */
    public function titleTaken(Request $request): JsonResponse
    {
        $request->validate(['title' => ['nullable', 'string', 'max:300'], 'except' => ['nullable', 'string']]);
        $except = $request->filled('except') ? Work::query()->firstWhere('slug', $request->string('except')) : null;
        $taken = WorksAdmin::titleTaken((string) $request->string('title'), $except);

        return response()->json(['taken' => $taken ? ['title' => $taken->title, 'href' => '/works/'.$taken->slug.'?tab=settings'] : null]);
    }

    /**
     * New work: title and kind (a novel may join a series, a single piece a collection), then straight to a blank page.
     */
    public function store(StoreWorkRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $kind = WorkKind::from($data['kind']);

        $work = DB::transaction(function () use ($data, $kind): Work {
            $title = WorksAdmin::cleanTitle($data['title']);
            $series = $kind === WorkKind::Novel && ! empty($data['series_id']) ? Series::query()->find($data['series_id']) : null;

            $work = Work::query()->create([
                'kind' => $kind,
                'collection_kind' => $kind === WorkKind::Collection ? CollectionKind::from($data['collection_kind'] ?? 'story') : null,
                'title' => $title,
                'title_key' => WorksAdmin::titleKey($title),
                'slug' => WorksAdmin::slugFor($title),
                'status' => WorkStatus::InProgress,
                'visibility' => Visibility::Private,
                'series_id' => $series?->id,
                'series_position' => $series ? $series->works()->max('series_position') + 1 : null,
                'cover_color' => WorksAdmin::NEW_COLOURS[array_rand(WorksAdmin::NEW_COLOURS)],
                'heading_style' => $kind === WorkKind::Novel ? HeadingStyle::ChapterWordLower : null,
            ]);

            $work->items()->forceCreate([
                'type' => $kind === WorkKind::Novel ? WorkItemType::Chapter : WorkItemType::Piece,
                'position' => 1,
                'status' => PublishStatus::Draft,
                'draft_saved_at' => now(),
            ]);

            if ($kind->isSingle()) {
                $collection = ! empty($data['new_collection'])
                    ? $this->newCollection($data['new_collection'], CollectionKind::from($kind->value))
                    : (! empty($data['collection_id']) ? Work::query()->find($data['collection_id']) : null);

                if ($collection) {
                    $this->moveIntoCollection($work, $collection);
                    $work->save();
                }
            }

            return $work;
        });

        return redirect(WorksAdmin::writeHref($work, $work->items()->first()));
    }

    /**
     * One setting at a time, as she changes it. Answers with the work's address (a new title moves it).
     */
    public function update(UpdateWorkRequest $request, Work $work): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $work): void {
            if (array_key_exists('title', $data)) {
                $this->retitle($work, $data['title']);
            }

            if (array_key_exists('kind', $data)) {
                $this->rekind($work, WorkKind::from($data['kind']), $data['collection_kind'] ?? null);
            } elseif (array_key_exists('collection_kind', $data) && $work->kind === WorkKind::Collection) {
                $work->collection_kind = CollectionKind::from($data['collection_kind']);
            }

            foreach (['description', 'content_notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $work->{$field} = trim((string) $data[$field]) === '' ? null : trim((string) $data[$field]);
                }
            }

            foreach (['status', 'heading_style', 'show_chapter_titles', 'show_pov', 'part_style', 'show_part_numbers', 'scene_break'] as $field) {
                if (array_key_exists($field, $data)) {
                    $work->{$field} = $data[$field];
                }
            }

            if (array_key_exists('part_word', $data)) {
                $word = trim((string) $data['part_word']);
                $work->part_word = $word === '' ? null : mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
            }

            if (array_key_exists('visibility', $data)) {
                $this->changeVisibility($work, Visibility::from($data['visibility']));
            }

            if (array_key_exists('series_id', $data)) {
                $this->joinSeries($work, $data['series_id'], $data['series_position'] ?? null);
            } elseif (array_key_exists('series_position', $data) && $work->series_id) {
                $work->series_position = $data['series_position'];
            }

            if (array_key_exists('genres', $data)) {
                $ids = collect($data['genres'])->map(fn (string $name): int => $this->genre($name)->id);
                $work->genres()->sync($ids->all());
            }

            if (array_key_exists('collection_id', $data) || array_key_exists('new_collection', $data)) {
                $target = ! empty($data['new_collection'])
                    ? $this->newCollection($data['new_collection'], CollectionKind::from($work->kind->value))
                    : (! empty($data['collection_id']) ? Work::query()->find($data['collection_id']) : null);
                $this->moveIntoCollection($work, $target);
            }

            $work->save();
        });

        $work->refresh();

        return response()->json([
            'slug' => $work->slug,
            'href' => '/works/'.$work->slug,
            'sub' => WorksAdmin::sub($work->load('series')),
            'collectionId' => $work->collection_id,
            'seriesId' => $work->series_id,
            'seriesPosition' => $work->series_position,
            'collections' => WorksAdmin::newWorkChoices()['collections'],
            'reviewers' => WorksAdmin::reviewersOn($work),
        ]);
    }

    /**
     * Delete: the work goes with its chapters and plan.
     */
    public function destroy(Work $work): RedirectResponse
    {
        DB::transaction(function () use ($work): void {
            if ($work->collection_id) {
                $this->moveIntoCollection($work, null);
            }

            $media = array_filter([$work->cover_media_id, $work->share_media_id]);
            SlugHistory::query()->where('sluggable_type', $work->getMorphClass())->where('sluggable_id', $work->id)->delete();
            $work->delete();

            foreach ($media as $id) {
                WorkCoverController::forget((int) $id);
            }
        });

        return redirect('/works');
    }

    private function retitle(Work $work, string $title): void
    {
        $title = WorksAdmin::cleanTitle($title);

        if ($title === $work->title) {
            return;
        }

        $work->title = $title;
        $work->title_key = WorksAdmin::titleKey($title);
        $slug = WorksAdmin::slugFor($title, $work);

        if ($slug === $work->slug) {
            return;
        }

        $type = $work->getMorphClass();

        /* readers' old links keep working once the work has been out; a draft's passing titles leave no trail */
        if ($work->first_published_at !== null) {
            SlugHistory::query()->firstOrCreate(['sluggable_type' => $type, 'sluggable_id' => $work->id, 'slug' => $work->slug]);
        }

        SlugHistory::query()->where('sluggable_type', $type)->where('sluggable_id', $work->id)->where('slug', $slug)->delete();
        $work->slug = $slug;
    }

    private function rekind(Work $work, WorkKind $kind, ?string $collectionKind): void
    {
        $work->kind = $kind;
        $work->collection_kind = $kind === WorkKind::Collection ? CollectionKind::from($collectionKind ?? $work->collection_kind?->value ?? 'story') : null;

        if ($kind->isSingle()) {
            $work->series_id = null;
            $work->series_position = null;
        }

        if ($kind === WorkKind::Novel && $work->heading_style === null) {
            $work->heading_style = HeadingStyle::ChapterWordLower;
        }

        if (! $kind->isSingle() && $work->collection_id) {
            $this->moveIntoCollection($work, null);
        }
    }

    /**
     * Private takes access away from every reviewer of the work; their comments stay with her.
     */
    private function changeVisibility(Work $work, Visibility $visibility): void
    {
        if ($visibility === Visibility::Private) {
            $work->reviewers()->detach();
            DB::table('reviewer_invite_work')->where('work_id', $work->id)->delete();
        }

        $work->visibility = $visibility;
    }

    private function joinSeries(Work $work, ?int $seriesId, ?int $position): void
    {
        if (! $seriesId) {
            $work->series_id = null;
            $work->series_position = null;

            return;
        }

        if ($work->series_id !== $seriesId) {
            $position ??= (int) Work::query()->where('series_id', $seriesId)->max('series_position') + 1;
        }

        $work->series_id = $seriesId;
        $work->series_position = $position ?? $work->series_position ?? 1;
    }

    private function genre(string $name): Genre
    {
        $name = WorksAdmin::cleanTitle($name);
        $found = Genre::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        return $found ?? Genre::query()->create(['name' => $name, 'position' => (int) Genre::query()->max('position') + 1]);
    }

    private function newCollection(string $title, CollectionKind $kind): Work
    {
        $title = WorksAdmin::cleanTitle($title);

        return Work::query()->create([
            'kind' => WorkKind::Collection,
            'collection_kind' => $kind,
            'title' => $title,
            'title_key' => WorksAdmin::titleKey($title),
            'slug' => WorksAdmin::slugFor($title),
            'status' => WorkStatus::InProgress,
            'visibility' => Visibility::Private,
            'cover_color' => '#977AA0',
        ]);
    }

    /**
     * A poem, story or essay sits in one collection at most: leave the old one (closing the gap), join the new one at the end.
     */
    private function moveIntoCollection(Work $work, ?Work $collection): void
    {
        if ($work->collection_id === $collection?->id) {
            return;
        }

        if ($work->collection_id) {
            $old = WorkItem::query()->where('work_id', $work->collection_id)->where('piece_work_id', $work->id)->first();

            if ($old) {
                $old->delete();
                WorkItem::query()->where('work_id', $work->collection_id)->where('position', '>', $old->position)->decrement('position');
            }
        }

        if ($collection) {
            $published = $work->first_published_at !== null;

            WorkItem::query()->forceCreate([
                'work_id' => $collection->id,
                'type' => WorkItemType::Piece,
                'position' => (int) WorkItem::query()->where('work_id', $collection->id)->max('position') + 1,
                'piece_work_id' => $work->id,
                'title' => $work->title,
                'status' => $published ? PublishStatus::Published : PublishStatus::Draft,
                'published_at' => $published ? now() : null,
                'live_updated_at' => $published ? now() : null,
            ]);
        }

        $work->collection_id = $collection?->id;
    }
}
