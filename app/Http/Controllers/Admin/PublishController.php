<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PublishStatus;
use App\Enums\WorkItemType;
use App\Http\Controllers\Controller;
use App\Models\Version;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\Publisher;
use App\Support\WritePage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Publish, Update, Schedule (WAT), Unpublish, and versions — from the writing screen. Each answers with the item's
 * new state for the header and the Versions list.
 */
class PublishController extends Controller
{
    public function publish(Request $request, Work $work, WorkItem $item): JsonResponse
    {
        $this->own($work, $item);

        if ($item->type !== WorkItemType::Part && trim((string) ($item->draft_text ?? $item->live_text)) === '') {
            throw ValidationException::withMessages(['body' => 'There’s nothing to publish yet.']);
        }

        Publisher::publish($item, $request->user());

        return $this->answer($item);
    }

    public function schedule(Request $request, Work $work, WorkItem $item): JsonResponse
    {
        $this->own($work, $item);
        abort_if($item->type === WorkItemType::Part || $item->status === PublishStatus::Published, 422);

        $data = $request->validate([
            'day' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ]);

        $at = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['day'].' '.$data['time'], (string) config('eyiwetemi.timezone'));

        if ($at->lte(now())) {
            throw ValidationException::withMessages(['day' => 'Pick a time that hasn’t passed.']);
        }

        Publisher::schedule($item, $at->utc());

        return $this->answer($item);
    }

    public function unschedule(Work $work, WorkItem $item): JsonResponse
    {
        $this->own($work, $item);

        if ($item->status === PublishStatus::Scheduled) {
            Publisher::unschedule($item);
        }

        return $this->answer($item);
    }

    public function unpublish(Request $request, Work $work, WorkItem $item): JsonResponse
    {
        $this->own($work, $item);

        if ($item->status === PublishStatus::Published) {
            Publisher::unpublish($item, $request->user());
        }

        return $this->answer($item);
    }

    /**
     * One version's page, to show with its changes since.
     */
    public function version(Work $work, WorkItem $item, Version $version): JsonResponse
    {
        $this->own($work, $item, $version);

        return response()->json(['body' => $version->body]);
    }

    public function restore(Request $request, Work $work, WorkItem $item, Version $version): JsonResponse
    {
        $this->own($work, $item, $version);
        Publisher::restore($item, $version, $request->user());

        return response()->json([...WritePage::state($item->refresh()), 'body' => $item->draft_body]);
    }

    private function own(Work $work, WorkItem $item, ?Version $version = null): void
    {
        abort_unless($item->work_id === $work->id, 404);
        abort_if($version && ($version->versionable_type !== $item->getMorphClass() || $version->versionable_id !== $item->id), 404);
    }

    private function answer(WorkItem $item): JsonResponse
    {
        return response()->json(WritePage::state($item->refresh()));
    }
}
