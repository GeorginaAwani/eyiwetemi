<?php

namespace App\Support;

use App\Models\NewsletterSend;
use App\Models\NewsletterSendItem;
use App\Models\Subscriber;
use App\Models\Work;
use Illuminate\Support\Str;

/**
 * The view data for the newsletter's emails: the send (what is in it for one reader, or for everyone in a preview or a
 * test) and the welcome letter. Links are absolute, on the public site, since the emails open from an inbox.
 */
class NewsletterEmail
{
    /**
     * The send's view data for one reader, or for everyone when there is no reader (previews and tests). Only what is not
     * left out, and only the kinds the reader chose, appears. Null when nothing is left for them.
     *
     * @return array<string, mixed>|null
     */
    public static function send(NewsletterSend $send, ?Subscriber $to, ?string $kind = null, bool $dark = false): ?array
    {
        $rows = $send->items()->get();
        $described = NewsletterItem::describe($rows, withText: true);

        $items = $rows
            ->filter(fn (NewsletterSendItem $row): bool => ! $row->left_out && isset($described[$row->id]))
            ->map(fn (NewsletterSendItem $row): array => $described[$row->id])
            ->filter(fn (array $item): bool => ($kind === null || $item['kind'] === $kind)
                && ($to === null || in_array($item['kind'], $to->kinds, true)))
            ->values();

        if ($items->isEmpty()) {
            return null;
        }

        return [
            'dark' => $dark,
            'palette' => self::palette($dark),
            'date' => $send->scheduled_for->copy()->setTimezone(self::zone())->format('l j F'),
            'lines' => self::paragraphs($send->intro_lines),
            'photo' => self::photo(),
            'items' => $items->map(fn (array $item): array => self::sendItem($item))->all(),
            'kinds' => self::footerKinds($to, $kind),
            'manage' => self::manageUrl($to),
            'stop' => self::manageUrl($to),
        ];
    }

    /**
     * The welcome letter's view data: her words, a photo of her, and the works it names that are public.
     *
     * @return array<string, mixed>
     */
    public static function welcome(?Subscriber $to = null, bool $dark = false): array
    {
        $welcome = NewsletterPlan::welcome();
        $works = Work::query()->listed()->with('cover')->whereIn('slug', $welcome['works'])->get()->keyBy('slug');

        return [
            'dark' => $dark,
            'palette' => self::palette($dark),
            'lines' => self::paragraphs($welcome['lines']),
            'photo' => self::photo(),
            'works' => collect($welcome['works'])
                ->map(fn (string $slug): ?Work => $works->get($slug))
                ->filter()
                ->map(fn (Work $work): array => [
                    'kind' => WorkPresenter::kindLabel($work),
                    'title' => $work->title,
                    'cover' => self::cover(WorkPresenter::cover($work)),
                ])
                ->values()
                ->all(),
            'kinds' => self::footerKinds($to, null),
            'manage' => self::manageUrl($to),
            'stop' => self::manageUrl($to),
        ];
    }

    /**
     * The colours of one palette: the light one, or the dark one for the preview's dark setting.
     *
     * @return array{bg: string, ink: string, text: string, muted: string, line: string, accent: string}
     */
    public static function palette(bool $dark): array
    {
        return $dark
            ? ['bg' => '#1C1A1B', 'ink' => '#DCD5D3', 'text' => '#BDB5B3', 'muted' => '#8A8284', 'line' => '#2F2C2D', 'accent' => '#D495A8']
            : ['bg' => '#FFFFFF', 'ink' => '#1E1C1D', 'text' => '#3B3739', 'muted' => '#6F6B6D', 'line' => '#E6E4E4', 'accent' => '#A24F68'];
    }

    /**
     * One item as the email draws it: the kind line (with reading time), the title, the excerpt, the picture for novels and
     * stories, and the link.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function sendItem(array $item): array
    {
        $showCover = in_array($item['kind'], ['novels', 'stories'], true) && $item['cover'] !== null;

        return [
            'kind' => $item['minutes'] !== null ? $item['where'].' · '.$item['minutes'].' min' : $item['where'],
            'title' => $item['title'],
            'poem' => $item['poem'],
            'excerpt' => $item['excerpt'],
            'cover' => $showCover ? self::cover($item['cover']) : null,
            'link' => self::siteUrl($item['href']),
            'linkText' => $item['linkText'],
        ];
    }

    /**
     * A picture as the email carries it: its colour, and its address on the public site when it has one.
     *
     * @param  array{src: string|null, color: string, dark: bool}  $cover
     * @return array{src: string|null, color: string}
     */
    private static function cover(array $cover): array
    {
        return [
            'src' => $cover['src'] === null ? null : self::absolute($cover['src']),
            'color' => $cover['color'],
        ];
    }

    /**
     * Who the footer says she sends to: the reader's kinds, or the one kind a preview is for, or all five.
     */
    private static function footerKinds(?Subscriber $to, ?string $kind): string
    {
        $kinds = match (true) {
            $kind !== null => [$kind],
            $to !== null => $to->kinds,
            default => array_keys(NewsletterItem::KIND_LABELS),
        };

        $labels = array_map(
            fn (string $name): string => NewsletterItem::KIND_LABELS[$name],
            array_values(array_intersect(array_keys(NewsletterItem::KIND_LABELS), $kinds)),
        );

        if (count($labels) <= 1) {
            return $labels[0] ?? '';
        }

        return implode(', ', array_slice($labels, 0, -1)).' and '.$labels[count($labels) - 1];
    }

    /**
     * The reader's manage page, where they change or stop what they get. A preview or test has no reader, so it points at
     * the subscribe form on the home page.
     */
    private static function manageUrl(?Subscriber $to): string
    {
        return $to === null
            ? self::siteUrl(route('home', absolute: false).'#nl')
            : self::siteUrl(route('emails.show', $to->manage_token, absolute: false));
    }

    /**
     * Her photo, on the public site, or null when she has none.
     */
    private static function photo(): ?string
    {
        $photo = ReaderPage::authorPhoto();

        return $photo === null ? null : self::absolute($photo);
    }

    /**
     * Lines of her own words, one paragraph each.
     *
     * @return list<string>
     */
    private static function paragraphs(?string $text): array
    {
        return array_values(array_filter(
            array_map(trim(...), preg_split('/\R+/', (string) $text) ?: []),
            fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * An address on the public site: a path becomes a full link, a full address stays as it is.
     */
    private static function absolute(string $address): string
    {
        return Str::startsWith($address, '/') ? self::siteUrl($address) : $address;
    }

    private static function siteUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    private static function zone(): string
    {
        return (string) config('eyiwetemi.timezone');
    }
}
