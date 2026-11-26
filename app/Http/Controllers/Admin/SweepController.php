<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkItemType;
use App\Http\Controllers\Controller;
use App\Models\SweepName;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\SweepNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Sweep's names (added by hand; the Plan's own come by themselves), the "mark names while writing" switch, and the
 * pages of the whole book for sweeping it at once.
 */
class SweepController extends Controller
{
    /**
     * Add a name, or change its readings when it's already there: "Xu Wei" → "Xú Wēi"; two readings as "Lǐ|Lí".
     */
    public function store(Request $request, Work $work): JsonResponse
    {
        $data = $request->validate([
            'plain' => ['required', 'string', 'max:120'],
            'readings' => ['nullable', 'string', 'max:400'],
        ]);

        $plain = trim((string) preg_replace('/\s+/u', ' ', $data['plain']));
        $readings = collect(explode('|', (string) ($data['readings'] ?? '')))->map(fn (string $r): string => trim($r))->filter()->values()->all();
        $row = SweepName::query()->where('work_id', $work->id)->whereRaw('lower(plain) = ?', [Str::lower($plain)])->first();

        if ($row) {
            $row->update(['readings' => $readings ?: [$plain]]);
        } else {
            SweepName::query()->create([
                'work_id' => $work->id,
                'plain' => $plain,
                'readings' => $readings ?: [$plain],
                'position' => (int) SweepName::query()->where('work_id', $work->id)->max('position') + 1,
            ]);
        }

        return response()->json(['names' => SweepNames::list($work)], 201);
    }

    public function destroy(Work $work, SweepName $name): JsonResponse
    {
        abort_unless($name->work_id === $work->id, 404);
        $name->delete();

        return response()->json(['names' => SweepNames::list($work)]);
    }

    /**
     * Mark names while writing (a faint underline; nothing in the text changes).
     */
    public function mark(Request $request, Work $work): JsonResponse
    {
        $work->forceFill(['mark_names' => $request->validate(['on' => ['required', 'boolean']])['on']])->save();

        return response()->json(['on' => $work->mark_names]);
    }

    /**
     * Every chapter's or piece's draft, for a sweep of the whole book.
     */
    public function pages(Work $work): JsonResponse
    {
        $items = $work->items()->where('type', '!=', WorkItemType::Part)->whereNull('piece_work_id')->get(['id', 'position', 'draft_body', 'live_body']);

        return response()->json(['pages' => $items->values()->map(fn (WorkItem $item, int $i): array => [
            'id' => $item->id,
            'number' => $i + 1,
            'body' => $item->draft_body ?? $item->live_body,
        ])->all()]);
    }
}
