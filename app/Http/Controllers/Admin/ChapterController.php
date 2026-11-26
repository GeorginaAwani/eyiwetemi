<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PublishStatus;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Http\Controllers\Controller;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\ChaptersAdmin;
use App\Support\Prose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A novel's or collection's contents: new chapters and parts, the order (drag or Alt + arrows), pieces a collection
 * borrows, and the novel's opening quote.
 */
class ChapterController extends Controller
{
    /**
     * New chapter (or part, or piece): at the end, or right after the one she's writing; then straight to it.
     */
    public function store(Request $request, Work $work): RedirectResponse
    {
        abort_unless(in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true), 404);

        $data = $request->validate([
            'type' => ['required', Rule::in(['chapter', 'part'])],
            'after' => ['nullable', 'integer'],
        ]);

        $part = $data['type'] === 'part';
        abort_if($part && $work->kind !== WorkKind::Novel, 422);

        $item = DB::transaction(function () use ($work, $data, $part): WorkItem {
            $after = isset($data['after']) ? $work->items()->whereKey($data['after'])->first() : null;
            $position = $after ? $after->position + 1 : (int) $work->items()->max('position') + 1;

            WorkItem::query()->where('work_id', $work->id)->where('position', '>=', $position)->increment('position');

            return $work->items()->forceCreate([
                'type' => $part ? WorkItemType::Part : ($work->kind === WorkKind::Novel ? WorkItemType::Chapter : WorkItemType::Piece),
                'position' => $position,
                'status' => PublishStatus::Draft,
                'draft_body' => $part ? null : Prose::empty(),
                'draft_saved_at' => now(),
            ]);
        });

        return redirect('/works/'.$work->slug.'/write/'.$item->id);
    }

    /**
     * The order she dragged them into.
     */
    public function order(Request $request, Work $work): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];
        $own = $work->items()->pluck('id')->all();

        abort_unless(count($ids) === count($own) && array_diff($own, $ids) === [], 422);

        DB::transaction(function () use ($ids): void {
            /* out of the way first, then into place, so no two share a position on the way */
            foreach ($ids as $i => $id) {
                WorkItem::query()->whereKey($id)->update(['position' => 100000 + $i]);
            }
            foreach ($ids as $i => $id) {
                WorkItem::query()->whereKey($id)->update(['position' => $i + 1]);
            }
        });

        return response()->json(['rows' => ChaptersAdmin::rows($work)]);
    }

    /**
     * A collection takes in poems, stories or essays she has already written (they stay on their own too).
     */
    public function borrow(Request $request, Work $work): JsonResponse
    {
        abort_unless($work->kind === WorkKind::Collection, 404);

        $ids = $request->validate([
            'works' => ['required', 'array', 'min:1'],
            'works.*' => ['integer', Rule::exists('works', 'id')->where('kind', $work->collection_kind?->value ?? 'story')],
        ])['works'];

        DB::transaction(function () use ($work, $ids): void {
            foreach (Work::query()->whereIn('id', $ids)->whereNull('collection_id')->get() as $piece) {
                $published = $piece->first_published_at !== null;
                $work->items()->forceCreate([
                    'type' => WorkItemType::Piece,
                    'position' => (int) $work->items()->max('position') + 1,
                    'piece_work_id' => $piece->id,
                    'title' => $piece->title,
                    'status' => $published ? PublishStatus::Published : PublishStatus::Draft,
                    'published_at' => $published ? now() : null,
                    'live_updated_at' => $published ? now() : null,
                ]);
                $piece->forceFill(['collection_id' => $work->id])->save();
            }
        });

        return response()->json(['rows' => ChaptersAdmin::rows($work)]);
    }

    /**
     * Take a borrowed piece out: it is on its own again.
     */
    public function release(Work $work, WorkItem $item): JsonResponse
    {
        abort_unless($item->work_id === $work->id && $item->piece_work_id, 404);

        DB::transaction(function () use ($work, $item): void {
            Work::query()->whereKey($item->piece_work_id)->where('collection_id', $work->id)->update(['collection_id' => null]);
            $item->delete();
            WorkItem::query()->where('work_id', $work->id)->where('position', '>', $item->position)->decrement('position');
        });

        return response()->json(['rows' => ChaptersAdmin::rows($work)]);
    }

    /**
     * The opening quote's own page, before chapter one.
     */
    public function opening(Work $work): Response
    {
        abort_unless($work->kind === WorkKind::Novel, 404);

        return Inertia::render('Admin/Opening', [
            'work' => ['slug' => $work->slug, 'title' => $work->title, 'href' => '/works/'.$work->slug.'?tab=chapters'],
            'quote' => ['q' => $work->opening_quote ?? '', 'by' => $work->opening_quote_by ?? ''],
        ]);
    }

    public function saveOpening(Request $request, Work $work): JsonResponse
    {
        abort_unless($work->kind === WorkKind::Novel, 404);

        $data = $request->validate(['q' => ['nullable', 'string', 'max:2000'], 'by' => ['nullable', 'string', 'max:300']]);
        $q = trim((string) ($data['q'] ?? ''));
        $by = trim((string) ($data['by'] ?? ''));
        $work->forceFill(['opening_quote' => $q === '' ? null : $q, 'opening_quote_by' => $by === '' ? null : $by])->save();

        return response()->json(['saved' => true]);
    }
}
