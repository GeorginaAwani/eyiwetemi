<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Support\NoticeAdmin;
use App\Support\SiteChrome;
use App\Support\SitePages;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one notice up or scheduled: written, started now or on a day, given an end, edited while it is up, and taken down (an
 * up notice goes to the earlier list; a scheduled one is cancelled). Each answer carries the notice tab's props.
 */
class SiteNoticeController extends Controller
{
    /** A notice starts at this time of the day (West Africa Time) when she picks a day. */
    private const START_TIME = '09:00';

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);

        if (Notice::query()->current()->exists()) {
            return response()->json(['message' => 'A notice is already up or scheduled. Take it down first.'], 409);
        }

        Notice::query()->create($data);

        return response()->json(['notice' => NoticeAdmin::props()]);
    }

    public function update(Request $request, Notice $notice): JsonResponse
    {
        if (! $notice->isCurrent()) {
            return response()->json(['message' => 'Only a notice that is up or scheduled can be changed.'], 409);
        }

        $notice->update($this->validated($request, $notice));

        return response()->json(['notice' => NoticeAdmin::props()]);
    }

    /**
     * Takes a notice down: one that is up goes to the earlier list, and one that is scheduled is cancelled.
     */
    public function down(Notice $notice): JsonResponse
    {
        if (! $notice->isCurrent()) {
            return response()->json(['message' => 'That notice has already come down.'], 409);
        }

        if ($notice->starts_at->isFuture()) {
            $notice->delete();
        } else {
            $notice->forceFill(['taken_down_at' => now()])->save();
        }

        return response()->json(['notice' => NoticeAdmin::props()]);
    }

    /**
     * The notice's text, link, start and end, checked and in the shape the notice keeps. A notice that is up and kept
     * with "now" keeps its start.
     *
     * @return array{text: string, link_target: string|null, starts_at: CarbonImmutable, ends_at: CarbonImmutable|null}
     */
    private function validated(Request $request, ?Notice $editing): array
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:70'],
            'link' => ['nullable', 'string', Rule::in(['', ...SiteChrome::noticeTargets()])],
            'starts' => ['required', 'string', 'regex:/^(now|\d{4}-\d{2}-\d{2})$/'],
            'ends' => ['nullable', 'array'],
            'ends.day' => ['required_with:ends', 'date_format:Y-m-d'],
            'ends.time' => ['required_with:ends', 'date_format:H:i'],
        ], [
            'text.required' => 'Write the notice first.',
            'text.max' => 'Keep the notice to 70 characters.',
        ]);

        $now = CarbonImmutable::now((string) config('eyiwetemi.timezone'));

        if ($data['starts'] === 'now') {
            $startsAt = $editing !== null && $editing->starts_at->lte($now) ? $editing->starts_at->toImmutable() : $now->utc();
        } else {
            if ($data['starts'] < $now->format('Y-m-d')) {
                throw ValidationException::withMessages(['starts' => 'Pick today or a later day.']);
            }

            $startsAt = SitePages::fromWat($data['starts'].' '.self::START_TIME);
        }

        $endsAt = isset($data['ends']) ? SitePages::fromWat($data['ends']['day'].' '.$data['ends']['time']) : null;

        if ($endsAt !== null && $endsAt->lte($startsAt)) {
            throw ValidationException::withMessages(['ends' => 'The end has to be after the start.']);
        }

        if ($endsAt !== null && $endsAt->isPast()) {
            throw ValidationException::withMessages(['ends' => 'The end has to be in the future.']);
        }

        return [
            'text' => $data['text'],
            'link_target' => ($data['link'] ?? '') === '' ? null : $data['link'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }
}
