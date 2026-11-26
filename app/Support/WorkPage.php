<?php

namespace App\Support;

use App\Enums\SitePageKey;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Note;
use App\Models\SitePage;
use App\Models\Work;
use Carbon\CarbonInterface;

/**
 * A novel's or collection's own page (W2 Margins): the picture and facts in a sticky margin with
 * In print under them; the title, status, genre, series, blurb, note and actions in the column;
 * then every chapter. Long lists fold so about ten rows show.
 */
class WorkPage
{
    /** Lists longer than this fold. */
    public const FOLD_OVER = 12;

    /** Rows that stay open above a folded tail. */
    public const OPEN_ROWS = 10;

    /**
     * Expects the work loaded with WorkPresenter::with() and `genres`.
     */
    public function __construct(private Work $work) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $work = $this->work;
        $card = WorkPresenter::card($work);
        $rows = WorkPresenter::numbered($work);
        $unit = WorkPresenter::unit($work);
        $count = $rows->count();
        $first = $rows->first();
        $newest = $rows->last();
        $printOnly = $work->free_chapters !== null;

        $chapters = WorkPresenter::allItems($work);
        if ($printOnly) {
            $chapters = array_values(array_filter($chapters, fn (array $c): bool => $c['number'] <= $work->free_chapters));
        }

        $actions = [];
        if ($first) {
            $actions[] = [
                'label' => $work->kind === WorkKind::Novel ? 'Start from chapter 1' : 'Start from the first '.$unit,
                'href' => WorkPresenter::itemHref($work, $first['number'], true),
                'style' => 'solid',
            ];
        }
        if ($newest && $newest !== $first && ! $printOnly && $work->status === WorkStatus::InProgress) {
            $actions[] = ['label' => 'Newest: '.$unit.' '.$newest['number'], 'href' => WorkPresenter::itemHref($work, $newest['number']), 'style' => 'link'];
        }

        $note = Note::query()->released()->where('work_id', $work->id)->latest('published_at')->first();

        return [
            'work' => [
                ...$card,
                'statusLine' => $work->kind === WorkKind::Collection ? 'A collection · '.WorkPresenter::units($work, $count) : WorkPresenter::statusLine($work),
                'genres' => $work->kind === WorkKind::Novel ? $work->genres->sortBy('position')->pluck('name')->take(2)->values()->all() : [],
                'blurb' => $work->description,
                'likes' => number_format((int) $work->likes_count),
                'comments' => number_format((int) $work->comments_count),
                'following' => ReaderMarks::followed('work', $work->id)->isNotEmpty(),
                'note' => $note && Reading::firstSentence($note->live_text) ? [
                    'label' => 'Note · '.Reading::day($note->published_at),
                    'text' => Reading::noteOpening($note->live_text),
                    'href' => '/notes#n-'.$note->id,
                ] : null,
                'printOnly' => $printOnly,
            ],
            'facts' => $this->facts($count),
            'prints' => $this->prints(),
            'actions' => $actions,
            'list' => [
                'title' => ucfirst($unit === 'story' ? 'stories' : $unit.'s'),
                'count' => $count,
                'untitled' => collect($chapters)->every(fn (array $c): bool => $c['title'] === null),
                'rows' => $chapters,
                'foldFrom' => count($chapters) > self::FOLD_OVER ? self::OPEN_ROWS : null,
            ],
        ];
    }

    /**
     * Started, Chapters, Reading time and Last, as in the margin.
     *
     * @return list<array{label: string, value: string}>
     */
    private function facts(int $count): array
    {
        $work = $this->work;
        $rows = WorkPresenter::numbered($work);
        $started = $rows->first()['item']->published_at ?? $work->first_published_at;
        $updated = WorkPresenter::updatedAt($work);
        $tz = config('eyiwetemi.timezone');
        $isCollection = $work->kind === WorkKind::Collection;

        $startedText = null;
        if ($started) {
            $from = $started->copy()->setTimezone($tz);
            $to = $updated?->copy()->setTimezone($tz);
            $startedText = $isCollection && $to && $to->year !== $from->year ? $from->year.'–'.$to->year : $from->format('F Y');
        }

        $unitWord = ucfirst($isCollection ? ($work->collection_kind?->value === 'story' ? 'stories' : $work->collection_kind?->value.'s') : 'chapters');
        $finished = $work->status === WorkStatus::Finished;

        return array_values(array_filter([
            $startedText ? ['label' => 'Started', 'value' => $startedText] : null,
            ['label' => $unitWord, 'value' => $finished || $isCollection ? (string) $count : $count.' so far'],
            ['label' => 'Reading time', 'value' => Reading::duration(WorkPresenter::minutes($work))],
            $updated ? ['label' => 'Last', 'value' => $finished && ! $isCollection ? $this->longMonth($updated) : Reading::day($updated)] : null,
        ]));
    }

    /**
     * The book's formats as In print lists them: one store links straight there; several unfold.
     *
     * @return list<array{format: string, stores: list<array{store: string, price: string, url: string}>, from: string|null}>
     */
    private function prints(): array
    {
        $books = collect(SitePage::query()->firstWhere('key', SitePageKey::InPrint)?->live['books'] ?? [])->keyBy('slug');
        $book = $books[$this->work->slug] ?? null;

        if ($book === null) {
            return [];
        }

        return collect($book['formats'] ?? [])->map(function (array $format): array {
            $stores = collect($format['stores'] ?? [])->map(fn (array $s): array => [
                'store' => (string) $s['store'],
                'price' => InPrint::money((int) $s['price']['amount'], (string) $s['price']['currency']),
                'url' => (string) $s['url'],
            ])->values()->all();

            $naira = collect($format['stores'] ?? [])->filter(fn (array $s): bool => ($s['price']['currency'] ?? null) === 'NGN')->sortBy('price.amount')->first()
                ?? collect($format['stores'] ?? [])->sortBy('price.amount')->first();

            return [
                'format' => (string) $format['format'],
                'stores' => $stores,
                'from' => count($stores) > 1 && $naira ? InPrint::money((int) $naira['price']['amount'], (string) $naira['price']['currency']) : null,
            ];
        })->values()->all();
    }

    private function longMonth(CarbonInterface $at): string
    {
        $local = $at->copy()->setTimezone(config('eyiwetemi.timezone'));

        return $local->year === now(config('eyiwetemi.timezone'))->year ? $local->format('F') : $local->format('F Y');
    }
}
