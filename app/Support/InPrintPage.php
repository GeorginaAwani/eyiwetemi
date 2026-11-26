<?php

namespace App\Support;

use App\Enums\SitePageKey;
use App\Models\SitePage;
use App\Models\Work;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * In print: her books with a price per format (one store links straight there, several fold out),
 * what's coming, and readings (upcoming first; past ones go under Past readings the day after).
 */
class InPrintPage
{
    /** The columns every list shows, in order; any other format she adds follows them. */
    private const COLUMNS = ['Paperback', 'Hardback', 'Ebook', 'Signed'];

    /**
     * @return array<string, mixed>|null
     */
    public function toArray(): ?array
    {
        $live = SitePage::query()->firstWhere('key', SitePageKey::InPrint)?->live;

        if (! is_array($live)) {
            return null;
        }

        $books = $live['books'] ?? [];
        $coming = $live['coming'] ?? [];
        $works = Work::query()
            ->listed()
            ->whereIn('slug', array_merge(array_column($books, 'slug'), array_column($coming, 'slug')))
            ->with('cover:id,disk,path')
            ->get(['id', 'slug', 'cover_media_id'])
            ->keyBy('slug');

        $extra = collect($books)->flatMap(fn (array $book): array => array_column($book['formats'] ?? [], 'format'))
            ->unique()
            ->reject(fn (string $format): bool => in_array($format, self::COLUMNS, true))
            ->values()
            ->all();

        [$upcoming, $past] = $this->readings($live['events'] ?? []);

        return [
            'columns' => [...self::COLUMNS, ...$extra],
            'books' => array_map(fn (array $book): array => [
                ...$this->book($book, $works->get($book['slug'])),
                'formats' => array_values(array_filter(array_map(fn (array $format): array => [
                    'format' => (string) $format['format'],
                    'from' => $this->from($format['stores'] ?? []),
                    'stores' => array_map(fn (array $store): array => [
                        'store' => (string) $store['store'],
                        'price' => $this->price($store['price'] ?? null),
                        'url' => $store['url'] ?? null,
                    ], $format['stores'] ?? []),
                ], $book['formats'] ?? []), fn (array $format): bool => $format['stores'] !== [])),
            ], $books),
            'coming' => array_map(fn (array $book): array => [
                ...$this->book($book, $works->get($book['slug'])),
                'when' => (string) ($book['when'] ?? ''),
                'preorder' => ($book['mode'] ?? '') === 'pre' && $this->price($book['price'] ?? null) && ! empty($book['url'])
                    ? ['price' => $this->price($book['price']), 'url' => $book['url']]
                    : null,
            ], $coming),
            'upcoming' => $upcoming,
            'past' => $past,
        ];
    }

    /**
     * @param  array<string, mixed>  $book
     * @return array<string, mixed>
     */
    private function book(array $book, ?Work $work): array
    {
        return [
            'id' => $work?->id,
            'title' => (string) $book['title'],
            'kind' => (string) ($book['kind'] ?? ''),
            'href' => $work ? WorkPresenter::href($work) : '/library',
            'color' => (string) ($book['cover'] ?? '#C24E77'),
            'image' => $work?->cover ? WorkPresenter::mediaUrl($work->cover->disk, $work->cover->path) : null,
        ];
    }

    /**
     * @param  array{amount?: int, currency?: string}|null  $price
     */
    private function price(?array $price): ?string
    {
        if (empty($price['amount']) || empty($price['currency'])) {
            return null;
        }

        return InPrint::money((int) $price['amount'], (string) $price['currency']);
    }

    /**
     * The "from" price of a format: its lowest naira price, or the first store's.
     *
     * @param  list<array<string, mixed>>  $stores
     */
    private function from(array $stores): ?string
    {
        $naira = array_values(array_filter($stores, fn (array $store): bool => ($store['price']['currency'] ?? null) === 'NGN'));
        usort($naira, fn (array $a, array $b): int => $a['price']['amount'] <=> $b['price']['amount']);

        return $this->price(($naira[0] ?? $stores[0] ?? [])['price'] ?? null);
    }

    /**
     * Upcoming soonest first; past newest first, from the day after.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function readings(array $events): array
    {
        $zone = (string) config('eyiwetemi.timezone');
        $today = now($zone)->startOfDay();

        /** @var Collection<int, array{at: CarbonInterface, event: array<string, mixed>}> $all */
        $all = collect($events)->map(fn (array $event): array => ['at' => Carbon::parse($event['starts_at'])->setTimezone($zone), 'event' => $event]);
        [$upcoming, $past] = $all->partition(fn (array $row): bool => $row['at']->copy()->startOfDay()->gte($today));

        return [
            $upcoming->sortBy(fn (array $row): int => $row['at']->getTimestamp())->map(fn (array $row): array => $this->reading($row['at'], $row['event'], false))->values()->all(),
            $past->sortByDesc(fn (array $row): int => $row['at']->getTimestamp())->map(fn (array $row): array => $this->reading($row['at'], $row['event'], true))->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function reading(CarbonInterface $at, array $event, bool $past): array
    {
        $time = $at->minute ? $at->format('g:i a') : $at->format('g a');
        $year = $at->year !== now((string) config('eyiwetemi.timezone'))->year ? ' · '.$at->year : '';
        $online = (bool) ($event['online'] ?? false);
        $cancelled = (bool) ($event['cancelled'] ?? false);

        return [
            'id' => (string) $event['id'],
            'day' => $at->day,
            'month' => $at->format('M'),
            'title' => (string) $event['title'],
            'online' => $online,
            'where' => ($online ? 'Online' : (string) ($event['place'] ?? '')).' · '.($past ? $at->year : $time.$year),
            'cancelled' => $cancelled,
            'link' => ! $past && ! $cancelled && ! empty($event['url']) ? ['label' => $online ? 'Join' : 'Details', 'url' => $event['url']] : null,
        ];
    }
}
