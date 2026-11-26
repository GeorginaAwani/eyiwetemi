<?php

namespace App\Support;

use App\Enums\SitePageKey;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Note;
use App\Models\SitePage;
use App\Models\SiteSetting;
use App\Models\Work;
use Illuminate\Support\Collection;

/**
 * Everything Home shows: the hero and its pile, About me, and a section per kind
 * (a lead, two more, and a link to the rest).
 */
class HomePage
{
    /** How many spines stand in the hero's pile. */
    private const PILE = 6;

    /** How many works sit in a section's "More" row. */
    private const MORE = 2;

    /** @var Collection<int, Work> */
    private Collection $works;

    public function __construct()
    {
        $this->works = WorkPresenter::query()->get()
            ->sortByDesc(fn (Work $work): int => WorkPresenter::updatedAt($work)?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $hero = $this->heroWork();
        $counts = LibraryCounts::get();

        return [
            'hero' => $hero ? $this->hero($hero) : null,
            'pile' => $this->pile($hero),
            'about' => $this->about(),
            'noteLine' => $this->noteLine(),
            'novels' => $this->novels($counts['novel']),
            'stories' => $this->pieces('story', $counts['story']),
            'poems' => $this->pieces('poem', $counts['poem']),
            'essays' => $this->pieces('essay', $counts['essay']),
        ];
    }

    /**
     * The pinned work while its pin lasts, otherwise whatever came out last.
     */
    private function heroWork(): ?Work
    {
        $pin = SiteSetting::get('home_pin');
        $until = isset($pin['until']) ? now()->parse($pin['until']) : null;

        if (isset($pin['slug']) && ($until === null || $until->isFuture())) {
            $pinned = $this->works->firstWhere('slug', $pin['slug']);

            if ($pinned !== null) {
                return $pinned;
            }
        }

        return $this->released()->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function hero(Work $work): array
    {
        $rows = WorkPresenter::numbered($work);
        $newest = $rows->last();
        $card = WorkPresenter::card($work);
        $hasUnits = in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true);
        $unit = WorkPresenter::unit($work);

        $label = $hasUnits && $newest
            ? ucfirst($unit).' '.$newest['number'].' · '.Reading::day($newest['item']->published_at)
            : WorkPresenter::kindLabel($work).' · '.$card['date'];

        if ($card['inPrint'] !== null) {
            $actions = [
                ['label' => 'See where to buy', 'href' => '/in-print', 'style' => 'solid'],
                ['label' => 'About the book', 'href' => $card['href'], 'style' => 'line'],
            ];
        } elseif ($hasUnits && $newest) {
            $first = $work->kind === WorkKind::Novel ? 'Start from chapter 1' : 'Start from the first '.$unit;
            $actions = [
                ['label' => $first, 'href' => WorkPresenter::itemHref($work, $rows->first()['number'], true), 'style' => 'solid'],
                ['label' => 'Newest: '.$unit.' '.$newest['number'], 'href' => WorkPresenter::itemHref($work, $newest['number']), 'style' => 'link'],
            ];
        } else {
            $actions = [['label' => 'Start reading', 'href' => $card['href'], 'style' => 'solid']];
        }

        return [...$card, 'label' => $label, 'actions' => $actions];
    }

    /**
     * The pile beside the hero: the newest works after it, as spines.
     *
     * @return list<array<string, mixed>>
     */
    private function pile(?Work $hero): array
    {
        return $this->released()
            ->reject(fn (Work $work): bool => $work->is($hero))
            ->take(self::PILE)
            ->map(function (Work $work): array {
                $card = WorkPresenter::card($work);
                $hasUnits = in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true);
                $kindWord = match ($work->kind) {
                    WorkKind::Story => 'story',
                    default => $work->kind->value,
                };
                $updated = WorkPresenter::updatedAt($work);
                $seed = crc32($work->slug);
                $width = 66 + $seed % 25;
                $room = 100 - $width;

                return [
                    'title' => $work->title,
                    'href' => $card['href'],
                    'color' => $card['cover']['color'],
                    'dark' => $card['cover']['dark'],
                    'sub' => $hasUnits ? ucfirst($kindWord).' · '.WorkPresenter::units($work, WorkPresenter::numbered($work)->count()) : null,
                    'meta' => $hasUnits
                        ? ($work->status === WorkStatus::Finished ? 'finished in '.Reading::month($updated) : Reading::day($updated))
                        : $kindWord.' · '.Reading::day($updated),
                    'height' => $hasUnits
                        ? (WorkPresenter::numbered($work)->count() >= 10 ? 124 : 96)
                        : (int) round(32 + 7 * sqrt($card['minutes'])),
                    'width' => $width,
                    'left' => round(min($room - 2, max(2, ($seed >> 8) % 100 / 100 * $room)), 1),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{quote: string|null, line: string|null}
     */
    private function about(): array
    {
        $home = SitePage::query()->firstWhere('key', SitePageKey::About)?->live['home'] ?? [];

        return ['quote' => $home['quote'] ?? null, 'line' => $home['line'] ?? null];
    }

    /**
     * The newest note, as the line under the header.
     *
     * @return array{label: string, text: string, href: string}|null
     */
    private function noteLine(): ?array
    {
        $note = Note::query()->released()->latest('published_at')->first();

        if ($note === null || Reading::firstSentence($note->live_text) === null) {
            return null;
        }

        return [
            'label' => 'Note · '.Reading::day($note->published_at),
            'text' => Reading::firstSentence($note->live_text),
            'href' => '/notes#n-'.$note->id,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function novels(int $total): ?array
    {
        $novels = $this->ofTab('novel');
        $lead = $novels->first(fn (Work $work): bool => WorkPresenter::numbered($work)->isNotEmpty());

        if ($lead === null) {
            return null;
        }

        $rows = WorkPresenter::numbered($lead);
        $newest = $rows->last();
        $note = Note::query()->released()->where('work_id', $lead->id)->latest('published_at')->first();

        return [
            'lead' => [
                ...WorkPresenter::card($lead),
                'statusLine' => WorkPresenter::statusLine($lead),
                'count' => $rows->count(),
                'chapters' => WorkPresenter::newestItems($lead, 3),
                'startHref' => WorkPresenter::itemHref($lead, $rows->first()['number'], true),
                'newest' => ['label' => 'Newest: chapter '.$newest['number'], 'href' => WorkPresenter::itemHref($lead, $newest['number'])],
                'note' => $note && Reading::firstSentence($note->live_text) ? [
                    'label' => 'Note · '.Reading::day($note->published_at),
                    'text' => Reading::firstSentence($note->live_text),
                    'href' => '/notes#n-'.$note->id,
                ] : null,
            ],
            'more' => $novels->reject(fn (Work $work): bool => $work->is($lead))
                ->take(self::MORE)
                ->map(fn (Work $work): array => [...WorkPresenter::card($work), 'firstLine' => $this->firstLine($work)])
                ->values()->all(),
            'total' => $total,
        ];
    }

    /**
     * Short stories, Poetry or Essays: the newest leads, the next two follow.
     *
     * @return array<string, mixed>|null
     */
    private function pieces(string $tab, int $total): ?array
    {
        $works = $this->ofTab($tab)->filter(fn (Work $work): bool => WorkPresenter::numbered($work)->isNotEmpty())->values();
        $lead = $works->first();

        if ($lead === null) {
            return null;
        }

        $first = WorkPresenter::body(WorkPresenter::numbered($lead)->first()['item']);

        return [
            'lead' => [
                ...WorkPresenter::card($lead),
                'excerpt' => $lead->description ?: Reading::firstParagraph($first),
                'stanza' => $tab === 'poem' ? Reading::firstStanza($first) : [],
                'startHref' => $lead->kind === WorkKind::Collection
                    ? WorkPresenter::itemHref($lead, 1, true)
                    : WorkPresenter::href($lead).'?start',
            ],
            'more' => $works->slice(1)->take(self::MORE)->map(function (Work $work) use ($tab): array {
                $body = WorkPresenter::body(WorkPresenter::numbered($work)->first()['item']);

                return [
                    ...WorkPresenter::card($work),
                    'firstLine' => $tab === 'poem'
                        ? (Reading::firstStanza($body)[0]['text'] ?? null)
                        : ($work->description ?: Reading::firstSentence(Reading::firstParagraph($body))),
                ];
            })->values()->all(),
            'total' => $total,
        ];
    }

    /**
     * The first sentence of a novel, shown on the page that shows when its small cover opens a crack.
     */
    private function firstLine(Work $work): ?string
    {
        $first = WorkPresenter::numbered($work)->first();

        return $first ? Reading::firstSentence(Reading::firstParagraph(WorkPresenter::body($first['item']))) : null;
    }

    /**
     * @return Collection<int, Work>
     */
    private function ofTab(string $tab): Collection
    {
        return $this->works->filter(fn (Work $work): bool => $work->libraryTab() === $tab)
            ->sortBy(fn (Work $work): int => $work->status === WorkStatus::ComingSoon ? 1 : 0)
            ->values();
    }

    /**
     * Works with something out, newest first.
     *
     * @return Collection<int, Work>
     */
    private function released(): Collection
    {
        return $this->works->filter(fn (Work $work): bool => WorkPresenter::numbered($work)->isNotEmpty())->values();
    }
}
