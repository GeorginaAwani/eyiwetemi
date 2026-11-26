<?php

namespace Database\Seeders;

use App\Enums\PublishStatus;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WritingDay;
use Illuminate\Database\Seeder;

/**
 * Words written per day, for the desk's "Words written" and the writing history: the works with drafts open get
 * the last four weeks, this week adding up to the admin prototype's 6,420.
 */
class WritingDaySeeder extends Seeder
{
    /** @var list<int> words per day, today first, for the most active work; the others write less */
    private const WEEK = [1240, 0, 980, 1610, 0, 1350, 1240];

    private const THIS_WEEK_TOTAL = 6420;

    public function run(): void
    {
        $works = Work::query()
            ->whereHas('items', fn ($query) => $query->where('status', PublishStatus::Draft)->whereNotNull('draft_saved_at'))
            ->orderByDesc(
                WorkItem::query()->select('draft_saved_at')->whereColumn('work_items.work_id', 'works.id')->orderByDesc('draft_saved_at')->limit(1)
            )
            ->limit(4)
            ->get();

        $today = now(config('eyiwetemi.timezone'))->startOfDay();
        $thisWeekLeft = self::THIS_WEEK_TOTAL;

        foreach ($works as $rank => $work) {
            foreach (range(0, 27) as $daysAgo) {
                $base = self::WEEK[$daysAgo % 7];
                $words = intdiv($base * (4 - $rank), 4 + $rank * 2);

                if ($daysAgo < 7) {
                    $words = $rank === $works->count() - 1 && $daysAgo === 6 ? max(0, $thisWeekLeft) : min($words, $thisWeekLeft);
                    $thisWeekLeft -= $words;
                }

                if ($words === 0) {
                    continue;
                }

                WritingDay::query()->create([
                    'day' => $today->copy()->subDays($daysAgo)->toDateString(),
                    'work_id' => $work->id,
                    'words_added' => $words,
                    'words_cut' => intdiv($words, 7),
                ]);
            }
        }
    }
}
