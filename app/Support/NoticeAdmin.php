<?php

namespace App\Support;

use App\Models\Notice;
use Illuminate\Support\Carbon;

/**
 * The notice tab's data: the notice that is up or scheduled, and the earlier ones (taken down or ended) with how many readers
 * closed each. Every time is West Africa Time.
 */
class NoticeAdmin
{
    /**
     * @return array{current: array{id: int, text: string, link: string, startsAt: string|null, endsAt: string|null, state: string}|null, earlier: list<array{id: int, when: string, text: string, closed: int}>}
     */
    public static function props(): array
    {
        $current = Notice::query()->current()->latest('starts_at')->first();

        return [
            'current' => $current !== null ? self::current($current) : null,
            'earlier' => Notice::query()->earlier()->orderByDesc('starts_at')->orderByDesc('id')->get()
                ->map(fn (Notice $notice): array => [
                    'id' => $notice->id,
                    'when' => self::when($notice),
                    'text' => $notice->text,
                    'closed' => $notice->closed_count,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The notice as the tab edits it: its link target (or none), its start and end, and whether it is up or scheduled.
     *
     * @return array{id: int, text: string, link: string, startsAt: string|null, endsAt: string|null, state: string}
     */
    public static function current(Notice $notice): array
    {
        return [
            'id' => $notice->id,
            'text' => $notice->text,
            'link' => $notice->link_target ?? '',
            'startsAt' => SitePages::wat($notice->starts_at),
            'endsAt' => SitePages::wat($notice->ends_at),
            'state' => $notice->starts_at->isFuture() ? 'sch' : 'up',
        ];
    }

    /**
     * The days a notice ran, as the earlier list writes them: "25 Nov", "1–14 Nov", "25 Nov–2 Dec". A year is added only when it
     * is not this year.
     */
    public static function when(Notice $notice): string
    {
        $zone = (string) config('eyiwetemi.timezone');
        $thisYear = now($zone)->year;
        $first = $notice->starts_at->copy()->setTimezone($zone)->startOfDay();
        $last = collect([$notice->ends_at, $notice->taken_down_at])->filter()->min() ?? $notice->starts_at;
        $last = $last->copy()->setTimezone($zone)->startOfDay();

        $yearOf = fn (Carbon $day): string => $day->year !== $thisYear ? ' '.$day->year : '';

        return match (true) {
            $first->equalTo($last) => $first->format('j M').$yearOf($last),
            $first->year === $last->year && $first->month === $last->month => $first->format('j').'–'.$last->format('j M').$yearOf($last),
            $first->year === $last->year => $first->format('j M').'–'.$last->format('j M').$yearOf($last),
            default => $first->format('j M Y').'–'.$last->format('j M Y'),
        };
    }
}
