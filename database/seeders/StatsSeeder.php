<?php

namespace Database\Seeders;

use App\Enums\PublishStatus;
use App\Enums\Visibility;
use App\Enums\WorkKind;
use App\Models\DailyBreakdown;
use App\Models\DailyStat;
use App\Models\Note;
use App\Models\Work;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Ninety days of reading counts, shaped like the prototype's stats: the site total, each public work and its
 * chapters or pieces, the diary notes, the site pages, and the source, country and device breakdowns.
 *
 * Readers are the unique readers a day; finished counts the readers who reached the end, so it never exceeds readers.
 * daily_visitors is never seeded: it is the real per-day visitor hash table.
 */
class StatsSeeder extends Seeder
{
    private const DAYS = 90;

    private const CHUNK = 500;

    /** Site reads on the first day; the baseline grows to about 180 a day over the window. */
    private const BASE_READS = 110;

    /** Sunday first, as Carbon's dayOfWeek: a mild weekly rhythm. */
    private const WEEKDAY_FACTOR = [1.06, 0.94, 0.98, 1.0, 1.02, 1.1, 1.12];

    private const PAGE_SHARE = 0.54;

    private const NOTE_SHARE = 0.031;

    private const CHAPTER_READERS = 0.72;

    public function run(): void
    {
        $today = Carbon::now('Africa/Lagos')->startOfDay();
        $windowStart = $today->copy()->subDays(self::DAYS - 1);

        $works = Work::query()
            ->where('visibility', Visibility::Public)
            ->whereHas('items', fn ($query) => $query->where('status', PublishStatus::Published))
            ->get();

        $profiles = [];
        $chapters = [];

        foreach ($works as $work) {
            $profiles[$work->id] = $this->profile($work);
            $chapters[$work->id] = $this->publishedChapters($work, $windowStart);
        }

        $notes = Note::query()->where('status', PublishStatus::Published)->get();
        $noteDays = [];

        foreach ($notes as $note) {
            $noteDays[$note->id] = $this->dayIndex($note->published_at, $windowStart);
        }

        $stats = [];
        $breakdowns = [];
        $now = now();

        for ($day = 0; $day < self::DAYS; $day++) {
            $date = $windowStart->copy()->addDays($day);
            $daysAgo = self::DAYS - 1 - $day;
            $siteReads = 0;
            $siteReaders = 0;
            $siteFinished = 0;

            $planned = (self::BASE_READS + 70 * $day / (self::DAYS - 1))
                * self::WEEKDAY_FACTOR[$date->dayOfWeek]
                * (0.9 + 0.2 * $this->hash("site:{$day}"));

            $weights = [];

            foreach ($works as $work) {
                if ($daysAgo > $profiles[$work->id]['start']) {
                    continue;
                }

                $event = SeedData::EVENTS[$day] ?? null;
                $multiplier = ($event !== null && $event[0] === $work->slug) ? $event[2] : 1.0;

                $weights[$work->id] = $profiles[$work->id]['share']
                    * $multiplier
                    * (0.85 + 0.3 * $this->hash("{$work->slug}:{$day}"));
            }

            $weightTotal = array_sum($weights);

            foreach ($weights as $workId => $weight) {
                $work = $works->firstWhere('id', $workId);
                $profile = $profiles[$workId];
                $reads = (int) round($planned * $weight / $weightTotal);
                $readers = (int) round($reads * $profile['readers'] * (0.92 + 0.16 * $this->hash("r{$work->slug}:{$day}")));
                $finished = (int) round($readers * $profile['finished']);

                $siteReads += $reads;
                $siteReaders += $readers;
                $siteFinished += $finished;

                $stats[] = $this->stat($date, 'work', $workId, $workId, $reads, $readers, $finished, $now);

                $this->chapterStats($stats, $date, $chapters[$workId], $reads, $day, $now);
            }

            $stats[] = $this->stat($date, 'site', 0, null, $siteReads, $siteReaders, $siteFinished, $now);
            $stats[] = $this->stat($date, 'page', 0, null, (int) round($siteReads * self::PAGE_SHARE), (int) round($siteReads * self::PAGE_SHARE * 0.55), 0, $now);

            $activeNotes = $notes->filter(fn (Note $note): bool => $noteDays[$note->id] <= $day)->values();

            foreach ($activeNotes as $note) {
                $reads = (int) round($siteReads * self::NOTE_SHARE / max(1, $activeNotes->count())
                    * (0.8 + 0.4 * $this->hash("note{$note->id}:{$day}")));
                $stats[] = $this->stat($date, 'note', $note->id, null, $reads, (int) round($reads * 0.5), 0, $now);
            }

            foreach ($this->breakdownsFor($date, $siteReads, $day) as $row) {
                $breakdowns[] = $row + ['created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach (array_chunk($stats, self::CHUNK) as $chunk) {
            DailyStat::query()->insert($chunk);
        }

        foreach (array_chunk($breakdowns, self::CHUNK) as $chunk) {
            DailyBreakdown::query()->insert($chunk);
        }
    }

    /**
     * @return array{share: float, start: int, readers: float, finished: float}
     */
    private function profile(Work $work): array
    {
        if (isset(SeedData::PROF[$work->slug])) {
            [$share, $start, $readers, $finished] = SeedData::PROF[$work->slug];

            return ['share' => (float) $share, 'start' => (int) $start, 'readers' => (float) $readers, 'finished' => (float) $finished];
        }

        return [
            'share' => 0.003 + $this->hash("share:{$work->slug}") * 0.004,
            'start' => 60 + (int) floor($this->hash("start:{$work->slug}") * 560),
            'readers' => $work->kind === WorkKind::Novel ? 0.3 : 0.86,
            'finished' => $work->kind === WorkKind::Poem ? 0.93 : 0.7,
        ];
    }

    /**
     * The published chapters or pieces of a work that have a day index in the window. Single pieces return none:
     * their work row is their whole reading.
     *
     * @return list<array{id: int, work_id: int, number: int, slug: string, from: int}>
     */
    private function publishedChapters(Work $work, Carbon $windowStart): array
    {
        $items = $work->items()->where('type', '!=', 'part')->get()->values();

        if ($items->count() < 2) {
            return [];
        }

        $chapters = [];

        foreach ($items as $index => $item) {
            if ($item->status !== PublishStatus::Published || $item->published_at === null) {
                continue;
            }

            $from = $this->dayIndex($item->published_at, $windowStart);

            if ($from >= self::DAYS) {
                continue;
            }

            $chapters[] = [
                'id' => $item->id,
                'work_id' => $work->id,
                'number' => $index + 1,
                'slug' => $work->slug,
                'from' => $from,
            ];
        }

        return $chapters;
    }

    /**
     * @param  list<array<string, mixed>>  $stats
     * @param  list<array{id: int, work_id: int, number: int, slug: string, from: int}>  $chapters
     */
    private function chapterStats(array &$stats, Carbon $date, array $chapters, int $workReads, int $day, Carbon $now): void
    {
        $active = array_values(array_filter($chapters, fn (array $chapter): bool => $chapter['from'] <= $day));

        if ($active === [] || $workReads === 0) {
            return;
        }

        $weights = [];

        foreach ($active as $index => $chapter) {
            $weights[$index] = (1 / (1 + 0.06 * ($chapter['number'] - 1)))
                * (0.9 + 0.2 * $this->hash("{$chapter['slug']}:c{$chapter['number']}:{$day}"));
        }

        $weightTotal = array_sum($weights);

        foreach ($active as $index => $chapter) {
            $reads = (int) round($workReads * $weights[$index] / $weightTotal);

            if ($reads === 0) {
                continue;
            }

            $readers = (int) round($reads * self::CHAPTER_READERS);
            $finished = (int) round($readers * (0.68 + 0.12 * $this->hash("f{$chapter['slug']}{$chapter['number']}")));

            $stats[] = $this->stat($date, 'chapter', $chapter['id'], $chapter['work_id'], $reads, $readers, $finished, $now);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stat(Carbon $date, string $subject, int $subjectId, ?int $workId, int $reads, int $readers, int $finished, Carbon $now): array
    {
        return [
            'day' => $date->toDateString(),
            'subject' => $subject,
            'subject_id' => $subjectId,
            'work_id' => $workId,
            'reads' => $reads,
            'readers' => $readers,
            'finished' => $finished,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Source, country and device rows for one day. Each dimension adds up to the site's reads for that day.
     *
     * @return list<array{day: string, dimension: string, value: string, reads: int}>
     */
    private function breakdownsFor(Carbon $date, int $siteReads, int $day): array
    {
        $rows = [];

        $sources = [];

        foreach (SeedData::SRC as $source) {
            $sources[] = (float) $source[2];
        }

        foreach ($this->allocate($siteReads, $sources) as $index => $reads) {
            $rows[] = $this->breakdown($date, 'source', strtolower(SeedData::SRC[$index][1]), $reads);
        }

        $countries = [];

        foreach (SeedData::CTY as $country) {
            $countries[] = (float) $country[2];
        }

        foreach ($this->allocate($siteReads, $countries) as $index => $reads) {
            $rows[] = $this->breakdown($date, 'country', strtoupper(SeedData::CTY[$index][0]), $reads);
        }

        $phone = 0.7 + 0.02 * $this->hash("device:{$day}");

        foreach ($this->allocate($siteReads, [$phone, 1 - $phone]) as $index => $reads) {
            $rows[] = $this->breakdown($date, 'device', ['phone', 'computer'][$index], $reads);
        }

        return array_values(array_filter($rows, fn (array $row): bool => $row['reads'] > 0));
    }

    /**
     * @return array{day: string, dimension: string, value: string, reads: int}
     */
    private function breakdown(Carbon $date, string $dimension, string $value, int $reads): array
    {
        return ['day' => $date->toDateString(), 'dimension' => $dimension, 'value' => $value, 'reads' => $reads];
    }

    /**
     * Splits a total across weights so the parts add up to the total exactly (largest remainder).
     *
     * @param  list<float>  $weights
     * @return list<int>
     */
    private function allocate(int $total, array $weights): array
    {
        $weightTotal = array_sum($weights);
        $parts = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            $exact = $total * $weight / $weightTotal;
            $parts[$index] = (int) floor($exact);
            $remainders[$index] = $exact - $parts[$index];
        }

        $left = $total - array_sum($parts);
        arsort($remainders);

        foreach (array_slice(array_keys($remainders), 0, $left) as $index) {
            $parts[$index]++;
        }

        ksort($parts);

        return array_values($parts);
    }

    private function dayIndex(?Carbon $at, Carbon $windowStart): int
    {
        if ($at === null) {
            return 0;
        }

        $local = $at->copy()->setTimezone('Africa/Lagos')->startOfDay();

        return max(0, (int) $windowStart->diffInDays($local, false));
    }

    /**
     * A deterministic value between 0 and 1, so the seeded numbers are the same on every run.
     */
    private function hash(string $seed): float
    {
        return (crc32($seed) % 10000) / 10000;
    }
}
