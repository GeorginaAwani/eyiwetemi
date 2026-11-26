<?php

namespace App\Support;

use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Models\Note;
use App\Models\Work;
use App\Models\WorkItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The RSS feeds: everything, or one kind. Novels arrive chapter by chapter; stories, poems and essays
 * as they come out; notes as she posts them. Books only in print stop at their free chapters.
 */
class Feed
{
    public const SIZE = 30;

    /** @var array<string, array{label: string, kind: WorkKind|null}> */
    public const KINDS = [
        'novels' => ['label' => 'Novels', 'kind' => WorkKind::Novel],
        'stories' => ['label' => 'Short stories', 'kind' => WorkKind::Story],
        'poetry' => ['label' => 'Poetry', 'kind' => WorkKind::Poem],
        'essays' => ['label' => 'Essays', 'kind' => WorkKind::Essay],
        'notes' => ['label' => 'Notes', 'kind' => null],
    ];

    public function __construct(private ?string $only = null) {}

    public function title(): string
    {
        return $this->only ? 'eyiwetemi · '.self::KINDS[$this->only]['label'] : 'eyiwetemi';
    }

    /**
     * Newest first.
     *
     * @return Collection<int, array{title: string, link: string, date: CarbonInterface, text: string}>
     */
    public function entries(): Collection
    {
        $entries = collect();

        if ($this->wants('novels')) {
            $entries = $entries->concat($this->chapters());
        }
        foreach (['stories', 'poetry', 'essays'] as $kind) {
            if ($this->wants($kind)) {
                $entries = $entries->concat($this->singles(self::KINDS[$kind]['kind']));
            }
        }
        if ($this->wants('notes')) {
            $entries = $entries->concat($this->notes());
        }

        return $entries->sortByDesc(fn (array $entry): int => $entry['date']->getTimestamp())->take(self::SIZE)->values();
    }

    private function wants(string $kind): bool
    {
        return $this->only === null || $this->only === $kind;
    }

    /**
     * @return Collection<int, array{title: string, link: string, date: CarbonInterface, text: string}>
     */
    private function chapters(): Collection
    {
        return WorkItem::query()
            ->released()
            ->where('type', WorkItemType::Chapter)
            ->whereHas('work', fn ($query) => $query->listed()->where('kind', WorkKind::Novel))
            ->with(['work' => fn ($query) => $query->select(['id', 'slug', 'title', 'heading_style', 'free_chapters'])->with('items:id,work_id,position')])
            ->latest('published_at')
            ->limit(self::SIZE)
            ->get(['id', 'work_id', 'position', 'title', 'published_at', 'live_body'])
            ->map(function (WorkItem $item): ?array {
                $number = $item->work->items->search(fn (WorkItem $other): bool => $other->id === $item->id) + 1;

                if ($item->work->free_chapters !== null && $number > $item->work->free_chapters) {
                    return null;
                }

                return [
                    'title' => $item->work->title.' · Chapter '.$number.($item->title ? ' · '.$item->title : ''),
                    'link' => url(WorkPresenter::itemHref($item->work, $number)),
                    'date' => $item->published_at,
                    'text' => (string) Reading::firstParagraph($item->live_body),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, array{title: string, link: string, date: CarbonInterface, text: string}>
     */
    private function singles(WorkKind $kind): Collection
    {
        return Work::query()
            ->listed()
            ->where('kind', $kind)
            ->whereNotNull('first_published_at')
            ->where('first_published_at', '<=', now())
            ->latest('first_published_at')
            ->limit(self::SIZE)
            ->get(['id', 'slug', 'title', 'description', 'first_published_at'])
            ->map(fn (Work $work): array => [
                'title' => $work->title,
                'link' => url(WorkPresenter::href($work)),
                'date' => $work->first_published_at,
                'text' => (string) ($work->description ?: Reading::firstParagraph(
                    WorkItem::query()->where('work_id', $work->id)->released()->orderBy('position')->first(['id', 'live_body'])?->live_body
                )),
            ]);
    }

    /**
     * @return Collection<int, array{title: string, link: string, date: CarbonInterface, text: string}>
     */
    private function notes(): Collection
    {
        return Note::query()
            ->released()
            ->latest('published_at')
            ->limit(self::SIZE)
            ->get(['id', 'live_text', 'published_at'])
            ->map(fn (Note $note): array => [
                'title' => (string) Reading::firstSentence($note->live_text),
                'link' => url('/notes#n-'.$note->id),
                'date' => $note->published_at,
                'text' => trim((string) $note->live_text),
            ]);
    }
}
