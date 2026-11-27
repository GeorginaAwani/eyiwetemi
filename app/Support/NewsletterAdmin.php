<?php

namespace App\Support;

use App\Enums\SendStatus;
use App\Models\NewsletterSend;
use App\Models\NewsletterSendItem;
use App\Models\NewsletterStop;
use App\Models\Subscriber;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The admin Newsletter page's data: the upcoming send and what is in it, the sends that went out, the subscribers (counts
 * and a page of them), the welcome letter, and the works she can add to it. Every time is West Africa Time.
 */
class NewsletterAdmin
{
    /** Subscribers per page in the list. */
    public const PER_PAGE = 14;

    /**
     * Everything the Newsletter page draws. Plans the upcoming send first, so it is synced before it is shown.
     *
     * @return array<string, mixed>
     */
    public static function props(string $tab): array
    {
        $next = NewsletterPlan::next();

        return [
            'tab' => match ($tab) {
                'subs' => 'u',
                'welcome' => 'w',
                default => 's',
            },
            'weekly' => NewsletterPlan::weekly(),
            'now' => now(self::zone())->format('Y-m-d\TH:i'),
            'next' => self::next($next),
            'sent' => self::sent(),
            'subscribers' => self::counts(),
            'welcome' => self::welcome(),
            'works' => self::works(),
        ];
    }

    /**
     * The upcoming send: when it goes out, whether she moved or held it, the words above it, how many readers get
     * something, and what is in it (left-out items included, so she can put them back).
     *
     * @return array<string, mixed>
     */
    public static function next(NewsletterSend $send): array
    {
        $rows = $send->items()->get();
        $described = NewsletterItem::describe($rows, withText: true);

        $items = $rows
            ->filter(fn (NewsletterSendItem $row): bool => isset($described[$row->id]))
            ->map(fn (NewsletterSendItem $row): array => self::nextItem($described[$row->id]))
            ->values()
            ->all();

        $at = $send->scheduled_for->copy()->setTimezone(self::zone());

        return [
            'id' => $send->id,
            'at' => $at->format('Y-m-d\TH:i'),
            'moved' => NewsletterPlan::isMoved($send),
            'held' => $send->status === SendStatus::Held,
            'after' => $at->copy()->addDays(7)->format('Y-m-d'),
            'lines' => (string) ($send->intro_lines ?? ''),
            'readers' => self::readers($items),
            'items' => $items,
        ];
    }

    /**
     * The sends that went out, were held or were skipped, newest first, with what each carried.
     *
     * @return list<array<string, mixed>>
     */
    private static function sent(): array
    {
        $sends = NewsletterSend::query()
            ->whereIn('status', [SendStatus::Sent, SendStatus::Held, SendStatus::Skipped])
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->get();

        $rows = NewsletterSendItem::query()
            ->whereIn('newsletter_send_id', $sends->pluck('id')->all())
            ->where('left_out', false)
            ->orderBy('position')
            ->get();

        $described = NewsletterItem::describe($rows);
        $rowsBySend = $rows->groupBy('newsletter_send_id');

        return $sends->map(fn (NewsletterSend $send): array => [
            'id' => $send->id,
            'day' => $send->scheduled_for->copy()->setTimezone(self::zone())->format('Y-m-d'),
            'status' => $send->status->value,
            'what' => self::what($rowsBySend->get($send->id, collect()), $described),
            'sent' => (int) $send->sent_count,
            'bounced' => (int) $send->bounced_count,
            'stopped' => (int) $send->stopped_count,
        ])->values()->all();
    }

    /**
     * The subscriber figures: totals, the week's changes, twelve month-end counts, the kinds readers chose, and the
     * unconfirmed addresses.
     *
     * @return array{total: int, newThisWeek: int, stoppedThisWeek: int, growth: list<int>, kinds: array<string, int>, unconfirmed: int}
     */
    private static function counts(): array
    {
        $since = now()->subDays(7);
        $thisMonth = now(self::zone())->startOfMonth();
        $growth = [];

        for ($monthsBack = 11; $monthsBack >= 0; $monthsBack--) {
            $monthEnd = $thisMonth->copy()->subMonths($monthsBack)->addMonth()->utc();
            $growth[] = Subscriber::query()->confirmed()->where('confirmed_at', '<', $monthEnd)->count();
        }

        $kinds = [];

        foreach (array_keys(NewsletterItem::KIND_LABELS) as $kind) {
            $kinds[$kind] = Subscriber::query()->confirmed()->whereJsonContains('kinds', $kind)->count();
        }

        return [
            'total' => Subscriber::query()->confirmed()->count(),
            'newThisWeek' => Subscriber::query()->confirmed()->where('confirmed_at', '>=', $since)->count(),
            'stoppedThisWeek' => NewsletterStop::query()->where('created_at', '>=', $since)->count(),
            'growth' => $growth,
            'kinds' => $kinds,
            'unconfirmed' => Subscriber::query()->whereNull('confirmed_at')->count(),
        ];
    }

    /**
     * One page of the subscriber list, or the one address searched for. Confirmed addresses are listed newest first,
     * unconfirmed ones newest first by when they signed up. Addresses are masked, except the one searched for.
     *
     * @return array{rows: list<array{id: int, email: string, kinds: string, joined: string}>, total: int, more: bool}
     */
    public static function subscriberPage(?string $kind, int $page, string $search, bool $unconfirmed): array
    {
        $needle = Str::lower(trim($search));

        if ($needle !== '') {
            $found = Subscriber::query()->confirmed()->where('email', $needle)->first();

            return [
                'rows' => $found === null ? [] : [self::subscriberRow($found, unmasked: true)],
                'total' => $found === null ? 0 : 1,
                'more' => false,
            ];
        }

        $query = Subscriber::query()
            ->when(
                $unconfirmed,
                fn (Builder $query) => $query->whereNull('confirmed_at'),
                fn (Builder $query) => $query->confirmed(),
            )
            ->when($kind !== null, fn (Builder $query) => $query->whereJsonContains('kinds', $kind))
            ->orderByDesc($unconfirmed ? 'created_at' : 'confirmed_at')
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $rows = $query->forPage($page, self::PER_PAGE)->get();

        return [
            'rows' => $rows->map(fn (Subscriber $subscriber): array => self::subscriberRow($subscriber))->values()->all(),
            'total' => $total,
            'more' => $page * self::PER_PAGE < $total,
        ];
    }

    /**
     * The welcome letter as the page draws it: her words, and the works it shows that are still public.
     *
     * @return array{lines: string, works: list<array{slug: string, title: string, kind: string, cover: array{src: string|null, color: string, dark: bool}}>}
     */
    public static function welcome(): array
    {
        $welcome = NewsletterPlan::welcome();

        return [
            'lines' => $welcome['lines'],
            'works' => self::cards($welcome['works']),
        ];
    }

    /**
     * Every public work by title, for adding to the welcome letter.
     *
     * @return list<array{slug: string, title: string, kind: string, cover: array{src: string|null, color: string, dark: bool}}>
     */
    private static function works(): array
    {
        return Work::query()->listed()->with('cover')->orderBy('title')->get()
            ->map(fn (Work $work): array => self::card($work))
            ->values()
            ->all();
    }

    /**
     * The public works among the given slugs, in the given order.
     *
     * @param  list<string>  $slugs
     * @return list<array{slug: string, title: string, kind: string, cover: array{src: string|null, color: string, dark: bool}}>
     */
    private static function cards(array $slugs): array
    {
        $works = Work::query()->listed()->with('cover')->whereIn('slug', $slugs)->get()->keyBy('slug');

        return collect($slugs)
            ->map(fn (string $slug): ?Work => $works->get($slug))
            ->filter()
            ->map(fn (Work $work): array => self::card($work))
            ->values()
            ->all();
    }

    /**
     * A work as the pickers show it.
     *
     * @return array{slug: string, title: string, kind: string, cover: array{src: string|null, color: string, dark: bool}}
     */
    private static function card(Work $work): array
    {
        return [
            'slug' => $work->slug,
            'title' => $work->title,
            'kind' => $work->kind->value,
            'cover' => WorkPresenter::cover($work),
        ];
    }

    /**
     * An item as the upcoming send's list shows it.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function nextItem(array $item): array
    {
        return [
            'key' => $item['key'],
            'kind' => $item['kind'],
            'title' => $item['title'],
            'where' => $item['where'],
            'text' => $item['text'],
            'when' => $item['when'],
            'scheduled' => $item['scheduled'],
            'cover' => $item['kind'] === 'notes' ? null : $item['cover'],
            'out' => $item['out'],
        ];
    }

    /**
     * How many confirmed readers get at least one of the items that are not left out.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private static function readers(array $items): int
    {
        $kinds = collect($items)->reject(fn (array $item): bool => $item['out'])->pluck('kind')->unique()->values()->all();

        if ($kinds === []) {
            return 0;
        }

        return Subscriber::query()
            ->confirmed()
            ->where(function (Builder $query) use ($kinds): void {
                foreach ($kinds as $kind) {
                    $query->orWhereJsonContains('kinds', $kind);
                }
            })
            ->count();
    }

    /**
     * What a send carried, in words: chapters and pieces by name, then the notes as "a note" or "N notes". Left-out items
     * are not in the list.
     *
     * @param  Collection<int, NewsletterSendItem>  $rows
     * @param  array<int, array<string, mixed>>  $described
     */
    private static function what(Collection $rows, array $described): string
    {
        $parts = [];
        $notes = 0;

        foreach ($rows as $row) {
            $item = $described[$row->id] ?? null;

            if ($item === null) {
                continue;
            }

            if ($item['kind'] === 'notes') {
                $notes++;

                continue;
            }

            $parts[] = $item['what'];
        }

        if ($notes === 1) {
            $parts[] = 'a note';
        } elseif ($notes > 1) {
            $parts[] = $notes.' notes';
        }

        return implode(', ', $parts);
    }

    /**
     * A subscriber as a row of the list: the address (masked unless asked not to), the kinds and the day they joined.
     *
     * @return array{id: int, email: string, kinds: string, joined: string}
     */
    private static function subscriberRow(Subscriber $subscriber, bool $unmasked = false): array
    {
        return [
            'id' => $subscriber->id,
            'email' => $unmasked ? $subscriber->email : self::mask($subscriber->email),
            'kinds' => self::kindsLabel($subscriber->kinds),
            'joined' => ($subscriber->confirmed_at ?? $subscriber->created_at)->copy()->setTimezone(self::zone())->format('Y-m-d'),
        ];
    }

    /**
     * An address with its local part cut to two characters: "ad•••@example.com".
     */
    public static function mask(string $email): string
    {
        return mb_substr(Str::before($email, '@'), 0, 2).'•••@'.Str::after($email, '@');
    }

    /**
     * The kinds a reader gets, as the list writes them: "Everything" for all five, else the labels in their usual order.
     *
     * @param  list<string>  $kinds
     */
    private static function kindsLabel(array $kinds): string
    {
        $ordered = array_values(array_intersect(array_keys(NewsletterItem::KIND_LABELS), $kinds));

        if (count($ordered) === count(NewsletterItem::KIND_LABELS)) {
            return 'Everything';
        }

        return implode(', ', array_map(fn (string $kind): string => NewsletterItem::KIND_LABELS[$kind], $ordered));
    }

    private static function zone(): string
    {
        return (string) config('eyiwetemi.timezone');
    }
}
