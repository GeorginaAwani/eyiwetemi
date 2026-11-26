<?php

namespace App\Support;

use App\Enums\Visibility;
use App\Enums\WorkStatus;
use App\Models\Series;
use App\Models\Work;
use Illuminate\Support\Collection;

/**
 * A series' page (W2 Margins): its books overlapped in the margin with Books and Since; the column lists
 * the books in order, each with its status, In print pill, or a way to hear when it's out.
 */
class SeriesPage
{
    /** @var Collection<int, Work> */
    private Collection $books;

    public function __construct(private Series $series)
    {
        $this->books = WorkPresenter::query()
            ->where('series_id', $series->id)
            ->orderBy('series_position')
            ->get();
    }

    /**
     * Whether anyone can see this series: it has a public book that's out.
     */
    public function isVisible(): bool
    {
        return $this->books->contains(fn (Work $book): bool => $book->visibility === Visibility::Public && $book->first_published_at !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $count = $this->books->count();
        $since = $this->books->pluck('first_published_at')->filter()->min();
        $first = $this->books->first(fn (Work $book): bool => WorkPresenter::numbered($book)->isNotEmpty());

        return [
            'series' => [
                'id' => $this->series->id,
                'name' => $this->series->name,
                'blurb' => $this->series->description,
                'statusLine' => 'A series · '.$count.' '.($count === 1 ? 'book' : 'books'),
                'facts' => array_values(array_filter([
                    ['label' => 'Books', 'value' => (string) $count],
                    $since ? ['label' => 'Since', 'value' => (string) $since->copy()->setTimezone(config('eyiwetemi.timezone'))->year] : null,
                ])),
            ],
            'actions' => $first ? [[
                'label' => 'Start from book '.$first->series_position,
                'href' => WorkPresenter::itemHref($first, WorkPresenter::numbered($first)->first()['number'], true),
                'style' => 'solid',
            ]] : [],
            'books' => $this->books->map(function (Work $book): array {
                $card = WorkPresenter::card($book);
                $soon = $book->status === WorkStatus::ComingSoon;

                return [
                    ...$card,
                    'position' => (int) $book->series_position,
                    'soon' => $soon,
                    'statusText' => match (true) {
                        $soon => 'Coming soon',
                        $book->status === WorkStatus::Finished => WorkPresenter::units($book, WorkPresenter::numbered($book)->count()),
                        default => WorkPresenter::units($book, WorkPresenter::numbered($book)->count()).' so far',
                    },
                    'href' => $soon ? null : $card['href'],
                ];
            })->values()->all(),
        ];
    }
}
