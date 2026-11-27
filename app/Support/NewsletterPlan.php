<?php

namespace App\Support;

use App\Enums\PublishStatus;
use App\Enums\SendStatus;
use App\Enums\WorkItemType;
use App\Models\NewsletterSend;
use App\Models\NewsletterSendItem;
use App\Models\Note;
use App\Models\SiteSetting;
use App\Models\Work;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The newsletter's plan: the weekly slot it goes out at, the upcoming send and what it carries, and the welcome letter.
 * Times are stored as UTC; the desk reads and picks them in West Africa Time.
 */
class NewsletterPlan
{
    /** Sundays at 09:00, the slot the prototype sends at. */
    private const DEFAULT_WEEKLY = ['day' => 0, 'time' => '09:00'];

    /** The welcome letter's words until she writes her own. */
    private const DEFAULT_WELCOME_LINES = 'Thank you for being here. I write novels a chapter at a time, and the odd poem when it rains. Every Sunday I’ll send you whatever is new, and nothing when nothing is.';

    /** The works the welcome letter names when all three are public. */
    private const DEFAULT_WELCOME_WORKS = ['the-ember-gate', 'night-bus', 'harmattan'];

    /** Works a welcome letter shows at most. */
    public const WELCOME_WORKS = 3;

    /**
     * The weekly slot: the day (0 is Sunday, 6 is Saturday) and the time (HH:MM, West Africa Time).
     *
     * @return array{day: int, time: string}
     */
    public static function weekly(): array
    {
        $stored = SiteSetting::get('newsletter.weekly');

        return is_array($stored)
            ? ['day' => (int) $stored['day'], 'time' => (string) $stored['time']]
            : self::DEFAULT_WEEKLY;
    }

    /**
     * Sets the weekly slot. The upcoming send moves to the new slot, unless she moved it by hand.
     */
    public static function setWeekly(int $day, string $time): void
    {
        SiteSetting::put('newsletter.weekly', ['day' => $day, 'time' => $time]);

        $upcoming = self::upcoming();

        if ($upcoming !== null && ! self::isMoved($upcoming)) {
            $upcoming->forceFill(['scheduled_for' => self::weeklySlotAfter(now())])->save();
        }
    }

    /**
     * The upcoming send, synced with what it should carry. When none is planned, one is created at the next weekly slot.
     */
    public static function next(): NewsletterSend
    {
        $send = self::upcoming() ?? NewsletterSend::query()->create([
            'scheduled_for' => self::weeklySlotAfter(now()),
            'status' => SendStatus::Planned,
            'intro_lines' => null,
        ])->refresh();

        self::sync($send);

        return $send;
    }

    /**
     * The earliest send still to go out: a planned one, or a held one whose time is still ahead.
     */
    public static function upcoming(): ?NewsletterSend
    {
        return NewsletterSend::query()
            ->where(fn (Builder $query) => $query
                ->where('status', SendStatus::Planned)
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', SendStatus::Held)
                    ->where('scheduled_for', '>', now())))
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->first();
    }

    /**
     * Holds a send, or lets a held one go out again.
     */
    public static function hold(NewsletterSend $send, bool $held): void
    {
        $send->forceFill(['status' => $held ? SendStatus::Held : SendStatus::Planned])->save();
    }

    /**
     * Moves a send to a time she picked, or back to the weekly slot when none is given.
     */
    public static function move(NewsletterSend $send, ?CarbonInterface $at): void
    {
        if ($at === null) {
            SiteSetting::put('newsletter.moved', null);
            $send->forceFill(['scheduled_for' => self::weeklySlotAfter(now())])->save();

            return;
        }

        SiteSetting::put('newsletter.moved', $send->id);
        $send->forceFill(['scheduled_for' => $at])->save();
    }

    /**
     * Whether she moved this send by hand, away from the weekly slot.
     */
    public static function isMoved(NewsletterSend $send): bool
    {
        return (int) SiteSetting::get('newsletter.moved') === $send->id;
    }

    /**
     * The first weekly slot after a moment, in UTC.
     */
    public static function weeklySlotAfter(CarbonInterface $after): CarbonImmutable
    {
        $weekly = self::weekly();
        [$hour, $minute] = array_map(intval(...), explode(':', $weekly['time']));
        $local = CarbonImmutable::instance($after)->setTimezone(self::zone());

        $daysAhead = ($weekly['day'] - $local->dayOfWeek + 7) % 7;
        $slot = $local->startOfDay()->addDays($daysAhead)->setTime($hour, $minute);

        if ($slot->lessThanOrEqualTo($local)) {
            $slot = $slot->addWeek();
        }

        return $slot->utc();
    }

    /**
     * Fills a send with the chapters, pieces and notes published or scheduled since the last send that went out (or was
     * skipped because she left everything out), up to this send's time. Rows she has left out keep that choice; rows that no longer qualify are removed.
     */
    public static function sync(NewsletterSend $send): void
    {
        $since = NewsletterSend::query()
            ->whereIn('status', [SendStatus::Sent, SendStatus::Skipped])
            ->where('scheduled_for', '<', $send->scheduled_for)
            ->orderByDesc('scheduled_for')
            ->first(['id', 'scheduled_for'])?->scheduled_for;
        $until = $send->scheduled_for;

        $items = WorkItem::query()
            ->whereIn('type', [WorkItemType::Chapter, WorkItemType::Piece])
            ->whereHas('work', fn (Builder $query) => $query->listed())
            /* a collection's piece goes out only while the piece itself is public */
            ->where(fn (Builder $query) => $query->whereNull('piece_work_id')->orWhereHas('pieceWork', fn (Builder $query) => $query->listed()))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => self::inWindow($query->where('status', PublishStatus::Published), 'published_at', $since, $until))
                ->orWhere(fn (Builder $query) => self::inWindow($query->where('status', PublishStatus::Scheduled), 'scheduled_at', $since, $until)))
            ->get(['id', 'status', 'published_at', 'scheduled_at']);

        $notes = Note::query()
            ->where('notify_followers', true)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => self::inWindow($query->where('status', PublishStatus::Published), 'published_at', $since, $until))
                ->orWhere(fn (Builder $query) => self::inWindow($query->where('status', PublishStatus::Scheduled), 'scheduled_at', $since, $until)))
            ->get(['id', 'status', 'published_at', 'scheduled_at']);

        $candidates = $items->concat($notes)
            ->map(fn (Model $item): array => [
                'item' => $item,
                'at' => $item->status === PublishStatus::Published ? $item->published_at : $item->scheduled_at,
            ])
            ->sortBy(fn (array $candidate): array => [
                $candidate['at']->getTimestamp(),
                $candidate['item']->getMorphClass(),
                $candidate['item']->getKey(),
            ])
            ->values();

        $keyOf = fn (Model $item): string => $item->getMorphClass().':'.$item->getKey();
        $keys = $candidates->map(fn (array $candidate): string => $keyOf($candidate['item']))->all();
        $existing = $send->items()->get()->keyBy(fn (NewsletterSendItem $row): string => $row->item_type.':'.$row->item_id);

        foreach ($existing as $key => $row) {
            if (! in_array($key, $keys, true)) {
                $row->delete();
            }
        }

        foreach ($candidates as $index => $candidate) {
            $position = $index + 1;
            $row = $existing->get($keyOf($candidate['item']));

            if ($row === null) {
                $send->items()->create([
                    'item_type' => $candidate['item']->getMorphClass(),
                    'item_id' => $candidate['item']->getKey(),
                    'left_out' => false,
                    'position' => $position,
                ]);
            } elseif ((int) $row->position !== $position) {
                $row->update(['position' => $position]);
            }
        }
    }

    /**
     * The welcome letter: her words and the works it shows, as she left them (or the defaults).
     *
     * @return array{lines: string, works: list<string>}
     */
    public static function welcome(): array
    {
        $stored = SiteSetting::get('newsletter.welcome');
        $stored = is_array($stored) ? $stored : [];

        return [
            'lines' => array_key_exists('lines', $stored) ? (string) $stored['lines'] : self::DEFAULT_WELCOME_LINES,
            'works' => array_key_exists('works', $stored) ? array_values($stored['works']) : self::defaultWelcomeWorks(),
        ];
    }

    /**
     * Saves the welcome letter. A part given as null keeps what it was.
     *
     * @param  list<string>|null  $works
     */
    public static function saveWelcome(?string $lines, ?array $works): void
    {
        $current = self::welcome();

        SiteSetting::put('newsletter.welcome', [
            'lines' => $lines ?? $current['lines'],
            'works' => $works ?? $current['works'],
        ]);
    }

    /**
     * The three named works when all of them are public, else the first three public works by title.
     *
     * @return list<string>
     */
    private static function defaultWelcomeWorks(): array
    {
        $named = Work::query()->listed()->whereIn('slug', self::DEFAULT_WELCOME_WORKS)->pluck('slug')->all();

        if (count($named) === count(self::DEFAULT_WELCOME_WORKS)) {
            return self::DEFAULT_WELCOME_WORKS;
        }

        return Work::query()->listed()->orderBy('title')->limit(self::WELCOME_WORKS)->pluck('slug')->all();
    }

    /**
     * A time column between the last send that went out (exclusive) and this send's time (inclusive).
     */
    private static function inWindow(Builder $query, string $column, ?CarbonInterface $since, CarbonInterface $until): Builder
    {
        return $query
            ->where($column, '<=', $until)
            ->when($since !== null, fn (Builder $query) => $query->where($column, '>', $since));
    }

    private static function zone(): string
    {
        return (string) config('eyiwetemi.timezone');
    }
}
