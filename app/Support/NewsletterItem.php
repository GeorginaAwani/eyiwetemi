<?php

namespace App\Support;

use App\Enums\CollectionKind;
use App\Enums\PublishStatus;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Models\NewsletterSendItem;
use App\Models\Note;
use App\Models\Work;
use App\Models\WorkItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * What one item in a newsletter says about itself: the reader's kind, its title and where it sits, its picture, the address
 * it opens, when it goes out, and (when asked) the words it opens with. The desk and the email both read items here.
 */
class NewsletterItem
{
    /** Most words an excerpt keeps, cut at a whole sentence or line. */
    public const EXCERPT_WORDS = 45;

    /** Reader kinds in the order every list shows them, with their labels. */
    public const KIND_LABELS = [
        'novels' => 'Novels',
        'stories' => 'Short stories',
        'poems' => 'Poetry',
        'essays' => 'Essays',
        'notes' => 'Notes',
    ];

    /** Columns of an item the newsletter reads, without its text. */
    private const ITEM_COLUMNS = ['id', 'work_id', 'piece_work_id', 'type', 'position', 'title', 'status', 'published_at', 'scheduled_at', 'live_words'];

    /** Columns of an item's text, read only when an excerpt or reading time is wanted. */
    private const ITEM_TEXT_COLUMNS = ['draft_body', 'live_body', 'draft_words'];

    /** Columns of a work the newsletter reads. */
    private const WORK_COLUMNS = ['id', 'title', 'slug', 'kind', 'collection_kind', 'cover_media_id', 'cover_color'];

    /**
     * Describes each row's item, keyed by the row's id. Items, works and covers load in a few queries. An item's text (its
     * excerpt and reading time) is read only when $withText is set.
     *
     * @param  Collection<int, NewsletterSendItem>  $rows
     * @return array<int, array{key: string, kind: string, title: string, where: string, what: string, href: string, linkText: string, cover: array{src: string|null, color: string, dark: bool}|null, when: string, scheduled: bool, out: bool, minutes: int|null, poem: bool, excerpt: list<string>, text: string}>
     */
    public static function describe(Collection $rows, bool $withText = false): array
    {
        $workType = (new WorkItem)->getMorphClass();
        $noteType = (new Note)->getMorphClass();

        $items = WorkItem::query()
            ->whereIn('id', $rows->where('item_type', $workType)->pluck('item_id')->unique()->all())
            ->select([...self::ITEM_COLUMNS, ...($withText ? self::ITEM_TEXT_COLUMNS : [])])
            ->with([
                'work' => fn (Relation $query) => $query->select(self::WORK_COLUMNS)->with('cover'),
                'pieceWork' => fn (Relation $query) => $query->select(['id', 'title']),
            ])
            ->get()
            ->keyBy('id');

        $notes = Note::query()
            ->whereIn('id', $rows->where('item_type', $noteType)->pluck('item_id')->unique()->all())
            ->select(['id', 'status', 'draft_text', 'live_text', 'published_at', 'scheduled_at'])
            ->get()
            ->keyBy('id');

        $siblings = WorkItem::query()
            ->whereIn('work_id', $items->pluck('work_id')->unique()->all())
            ->where('type', '!=', WorkItemType::Part)
            ->orderBy('position')
            ->get(['id', 'work_id', 'type', 'position'])
            ->groupBy('work_id');

        $sources = $withText
            ? WorkItem::query()
                ->whereIn('work_id', $items->pluck('piece_work_id')->filter()->unique()->all())
                ->orderBy('position')
                ->get(['id', 'work_id', 'position', 'draft_body', 'live_body', 'draft_words', 'live_words'])
                ->groupBy('work_id')
                ->map(fn (Collection $group): WorkItem => $group->first())
            : collect();

        $described = [];

        foreach ($rows as $row) {
            if ($row->item_type === $workType && $items->has($row->item_id)) {
                $item = $items->get($row->item_id);
                $described[$row->id] = self::chapter($row, $item, $siblings->get($item->work_id, collect()), $sources, $withText);
            } elseif ($row->item_type === $noteType && $notes->has($row->item_id)) {
                $described[$row->id] = self::note($row, $notes->get($row->item_id));
            }
        }

        return $described;
    }

    /**
     * The reader kind of a work: novels, stories, poems or essays. A collection reads as its own kind.
     */
    public static function kindOf(Work $work): string
    {
        return match ($work->kind) {
            WorkKind::Novel => 'novels',
            WorkKind::Story => 'stories',
            WorkKind::Poem => 'poems',
            WorkKind::Essay => 'essays',
            WorkKind::Collection => match ($work->collection_kind) {
                CollectionKind::Poem => 'poems',
                CollectionKind::Essay => 'essays',
                default => 'stories',
            },
        };
    }

    /**
     * The first words of a text, up to the limit and no further, cut at whole sentences (or whole lines for a poem).
     * A poem's blank lines stay as gaps. A first sentence longer than the limit is cut short with an ellipsis.
     *
     * @param  list<string>  $units  Paragraphs, or lines for a poem.
     * @return list<string>
     */
    public static function excerpt(array $units, bool $poem): array
    {
        $kept = [];
        $count = 0;

        foreach ($units as $unit) {
            $words = Prose::count($unit);

            if ($words === 0) {
                $kept[] = $unit;

                continue;
            }

            if ($count >= self::EXCERPT_WORDS) {
                break;
            }

            if ($count + $words <= self::EXCERPT_WORDS) {
                $kept[] = $unit;
                $count += $words;

                continue;
            }

            if (! $poem) {
                $taken = [];

                foreach (preg_split('/(?<=[.!?…])\s+/u', $unit) ?: [$unit] as $sentence) {
                    $sentenceWords = Prose::count($sentence);

                    if ($count + $sentenceWords > self::EXCERPT_WORDS) {
                        break;
                    }

                    $taken[] = $sentence;
                    $count += $sentenceWords;
                }

                if ($taken !== []) {
                    $kept[] = implode(' ', $taken);
                } elseif ($kept === []) {
                    $kept[] = implode(' ', array_slice(preg_split('/\s+/u', trim($unit)) ?: [], 0, self::EXCERPT_WORDS)).'…';
                }
            }

            break;
        }

        $kept = array_values($kept);

        while ($kept !== [] && $kept[0] === '') {
            array_shift($kept);
        }

        while ($kept !== [] && end($kept) === '') {
            array_pop($kept);
        }

        return $kept;
    }

    /**
     * A chapter or piece as the newsletter describes it. A novel's chapters and a collection's pieces are numbered when the
     * work has several; otherwise, and for a single story, poem or essay, it is named by its kind.
     *
     * @param  Collection<int, WorkItem>  $siblings  The work's chapters and pieces in reading order.
     * @param  Collection<int|string, WorkItem>  $sources  The first item of each piece's own work, by work id.
     * @return array<string, mixed>
     */
    private static function chapter(NewsletterSendItem $row, WorkItem $item, Collection $siblings, Collection $sources, bool $withText): array
    {
        $work = $item->work;
        $kind = self::kindOf($work);
        $single = ! in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true);
        $numbered = ! $single && $siblings->count() > 1;
        $number = $single ? null : self::numberOf($item, $siblings);
        $unit = $single ? null : WorkPresenter::unit($work).' '.$number;
        $scheduled = $item->status === PublishStatus::Scheduled;
        $title = (string) ($item->title ?: ($item->pieceWork?->title ?? ($single ? $work->title : '')));

        $described = [
            'key' => 'i'.$row->id,
            'kind' => $kind,
            'title' => $title,
            'where' => $numbered ? $work->title.', '.$unit : self::kindName($kind),
            'what' => $numbered && $item->type === WorkItemType::Chapter ? ucfirst((string) $unit) : ($title !== '' ? $title : $work->title),
            'href' => $single ? WorkPresenter::href($work) : WorkPresenter::itemHref($work, (int) $number),
            'linkText' => match ($kind) {
                'novels' => 'Read chapter '.$number,
                'stories' => 'Read the story',
                'poems' => 'Read the poem',
                default => 'Read the essay',
            },
            'cover' => WorkPresenter::cover($work),
            'when' => self::when($scheduled, $item->scheduled_at, $item->published_at),
            'scheduled' => $scheduled,
            'out' => (bool) $row->left_out,
            'minutes' => null,
            'poem' => $kind === 'poems',
            'excerpt' => [],
            'text' => '',
        ];

        if ($withText) {
            $source = $item->piece_work_id !== null ? $sources->get($item->piece_work_id) : $item;
            $body = $scheduled
                ? ($source?->draft_body ?? $source?->live_body)
                : ($source?->live_body ?? $source?->draft_body);

            $described['minutes'] = Reading::minutes(Prose::words($body));
            $described['excerpt'] = self::excerpt(self::units($body, $described['poem']), $described['poem']);
            $described['text'] = implode($described['poem'] ? "\n" : "\n\n", $described['excerpt']);
        }

        return $described;
    }

    /**
     * A note as the newsletter describes it: its words as plain text, and where it opens.
     *
     * @return array<string, mixed>
     */
    private static function note(NewsletterSendItem $row, Note $note): array
    {
        $scheduled = $note->status === PublishStatus::Scheduled;
        $text = (string) ($scheduled ? $note->draft_text : ($note->live_text ?? $note->draft_text));
        $excerpt = self::excerpt(array_values(array_filter(
            array_map(trim(...), preg_split('/\R\R+/', $text) ?: []),
            fn (string $paragraph): bool => $paragraph !== '',
        )), false);

        return [
            'key' => 'i'.$row->id,
            'kind' => 'notes',
            'title' => '',
            'where' => 'A note',
            'what' => 'a note',
            'href' => '/notes',
            'linkText' => 'Read on the Diary',
            'cover' => null,
            'when' => self::when($scheduled, $note->scheduled_at, $note->published_at),
            'scheduled' => $scheduled,
            'out' => (bool) $row->left_out,
            'minutes' => null,
            'poem' => false,
            'excerpt' => $excerpt,
            'text' => $text,
        ];
    }

    /**
     * "A novel", "A short story", "A poem" or "An essay" for a reader kind.
     */
    public static function kindName(string $kind): string
    {
        return match ($kind) {
            'novels' => 'A novel',
            'stories' => 'A short story',
            'poems' => 'A poem',
            default => 'An essay',
        };
    }

    /**
     * The chapter or piece's place among its work's chapters and pieces, counted from one.
     *
     * @param  Collection<int, WorkItem>  $siblings
     */
    public static function numberOf(WorkItem $item, Collection $siblings): int
    {
        $index = $siblings->values()->search(fn (WorkItem $sibling): bool => (int) $sibling->id === (int) $item->id);

        return $index === false ? 1 : $index + 1;
    }

    /**
     * The paragraphs (or a poem's lines) of an editor document, as plain text. Scene breaks and pictures are left out.
     *
     * @param  array<string, mixed>|null  $body
     * @return list<string>
     */
    private static function units(?array $body, bool $poem): array
    {
        $units = [];

        foreach ($body['content'] ?? [] as $node) {
            if (in_array($node['type'] ?? null, ['sceneBreak', 'image'], true)) {
                continue;
            }

            $text = trim(Prose::plain($node));

            if ($text !== '' || $poem) {
                $units[] = $text;
            }
        }

        return $units;
    }

    /**
     * When an item goes out, in West Africa Time: a day for a published one, a day and time for a scheduled one.
     */
    private static function when(bool $scheduled, ?CarbonInterface $scheduledAt, ?CarbonInterface $publishedAt): string
    {
        $at = $scheduled ? $scheduledAt : $publishedAt;

        if ($at === null) {
            return '';
        }

        return $at->copy()->setTimezone(config('eyiwetemi.timezone'))->format($scheduled ? 'Y-m-d\TH:i' : 'Y-m-d');
    }
}
