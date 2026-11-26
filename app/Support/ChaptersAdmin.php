<?php

namespace App\Support;

use App\Enums\Visibility;
use App\Enums\WorkItemType;
use App\Models\Work;
use App\Models\WorkItem;

/**
 * A novel's or collection's chapters, parts and pieces as the Chapters tab and the writing screen's Chapters panel
 * list them: number, title, POV, printed pages and where each stands (Draft, Scheduled, Published, Changes…).
 */
class ChaptersAdmin
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(Work $work): array
    {
        $items = $work->items()->with(['povCharacter:id,name', 'pieceWork:id,slug,title,kind'])->get();
        $latest = $items->where('type', '!=', WorkItemType::Part)->sortByDesc('draft_saved_at')->first();
        $chapter = 0;
        $part = 0;

        /* a borrowed piece's own page holds its words and its state */
        $own = WorkItem::query()
            ->whereIn('work_id', $items->pluck('piece_work_id')->filter())
            ->orderBy('position')
            ->get()
            ->unique('work_id')
            ->keyBy('work_id');

        return $items->map(function (WorkItem $item) use ($work, $latest, $own, &$chapter, &$part): array {
            $isPart = $item->type === WorkItemType::Part;
            $source = $item->piece_work_id ? ($own[$item->piece_work_id] ?? $item) : $item;
            $words = WorksAdmin::words($source);

            return [
                'id' => $item->id,
                'type' => $item->type->value,
                'number' => $isPart ? ++$part : ++$chapter,
                'title' => $item->piece_work_id ? ($item->pieceWork->title ?? $item->title ?? '') : ($item->title ?? ''),
                'description' => $item->description ?? '',
                'pov' => $item->povCharacter?->name,
                'words' => $words,
                'pages' => $isPart ? WorksAdmin::PART_PAGES : ($words ? WorksAdmin::pagesOf($work, $source) : 0),
                'state' => Publisher::state($source),
                'scheduledAt' => $source->scheduled_at?->toIso8601String(),
                'href' => $item->piece_work_id && $item->pieceWork
                    ? '/works/'.$item->pieceWork->slug.'/write/'.$source->id
                    : '/works/'.$work->slug.'/write/'.$item->id,
                'ref' => $item->piece_work_id ? $item->pieceWork?->slug : null,
                'now' => $latest !== null && $latest->id === $item->id,
            ];
        })->values()->all();
    }

    /**
     * "Public · 214 followers", "Reviewers · 3", "Private · only you": who sees it once it's out.
     */
    public static function audience(Work $work): string
    {
        return match ($work->visibility) {
            Visibility::Public => 'Public · '.number_format($n = $work->follows()->count()).($n === 1 ? ' follower' : ' followers'),
            Visibility::Reviewers => 'Reviewers · '.$work->reviewers()->count(),
            Visibility::Private => 'Private · only you',
        };
    }
}
