<?php

namespace App\Support;

use App\Enums\SitePageKey;
use App\Models\SitePage;

class InPrint
{
    /** @var array<string, string> */
    private const SYMBOLS = ['NGN' => '₦', 'USD' => '$', 'GBP' => '£', 'EUR' => '€'];

    /**
     * The lowest price of a book's first format (its paperback, usually), for the "In print · from ₦8,500" pill.
     * Naira first, as on In print.
     */
    public static function fromPrice(string $slug): ?string
    {
        $book = self::books()[$slug] ?? null;

        if ($book === null) {
            return null;
        }

        $prices = [];
        foreach ($book['formats'][0]['stores'] ?? [] as $store) {
            if (isset($store['price']['amount'], $store['price']['currency'])) {
                $prices[] = $store['price'];
            }
        }

        if ($prices === []) {
            return null;
        }

        $naira = array_values(array_filter($prices, fn (array $p): bool => $p['currency'] === 'NGN'));
        $pool = $naira !== [] ? $naira : $prices;
        usort($pool, fn (array $a, array $b): int => $a['amount'] <=> $b['amount']);

        return self::money((int) $pool[0]['amount'], (string) $pool[0]['currency']);
    }

    /**
     * A price as the site writes it: naira without kobo ("₦8,500"), other currencies with cents ("$12.99").
     */
    public static function money(int $minor, string $currency): string
    {
        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';

        if ($currency === 'NGN') {
            return $symbol.number_format(intdiv($minor, 100));
        }

        return $symbol.number_format($minor / 100, 2);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function books(): array
    {
        return once(function (): array {
            $live = SitePage::query()->firstWhere('key', SitePageKey::InPrint)?->live;

            return collect($live['books'] ?? [])->keyBy('slug')->all();
        });
    }
}
