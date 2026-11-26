<?php

namespace App\Support;

use App\Enums\ResearchKind;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Models\Character;
use App\Models\CharacterLink;
use App\Models\PlotPoint;
use App\Models\PlotThread;
use App\Models\ResearchItem;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WorldEntry;
use App\Models\WorldGroup;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Plan tab's page: characters (their links, points of view and the chapters they turn up in), the world's groups
 * and entries, plot threads and their points, research and its ties, the plan's notes, and the chapter list the tab
 * picks from. A poem has no plan.
 */
class PlanPage
{
    /**
     * The tab's props: the sections the work's kind shows, and everything in them.
     *
     * @return array{sections: list<string>, chars: list<array<string, mixed>>, groups: list<array<string, mixed>>, threads: list<array<string, mixed>>, points: list<array<string, mixed>>, research: list<array<string, mixed>>, notes: string, chapters: list<array<string, mixed>>}
     */
    public static function props(Work $work): array
    {
        $items = $work->items()
            ->with('pieceWork:id,title')
            ->get(['id', 'work_id', 'type', 'position', 'title', 'piece_work_id', 'pov_character_id', 'draft_text', 'live_text']);
        $sources = self::sources($items);
        $folded = self::foldedChapters($items, $sources);

        $characters = $work->characters()
            ->with(['links' => fn ($query) => $query->orderBy('id')])
            ->orderBy('position')->orderBy('id')
            ->get();
        $groups = $work->worldGroups()
            ->with(['entries' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->orderBy('position')->orderBy('id')
            ->get();
        $threads = $work->plotThreads()->orderBy('position')->orderBy('id')->get();
        $research = $work->researchItems()
            ->with(['media:id,disk,path,size', 'ties' => fn ($query) => $query->orderBy('id')])
            ->orderBy('position')->orderBy('id')
            ->get();
        $known = self::knownTies($characters, $groups, $threads, $items);

        return [
            'sections' => self::sections($work),
            'chars' => $characters->map(fn (Character $character): array => self::character($character, $items, $folded))->values()->all(),
            'groups' => $groups->map(fn (WorldGroup $group): array => self::group($group, $folded))->values()->all(),
            'threads' => $threads->map(fn (PlotThread $thread): array => [
                'id' => $thread->id,
                'name' => $thread->name,
                'colour' => (int) $thread->colour,
            ])->values()->all(),
            'points' => PlotPoint::query()
                ->whereIn('plot_thread_id', $threads->pluck('id'))
                ->orderBy('position')->orderBy('id')
                ->get()
                ->map(fn (PlotPoint $point): array => [
                    'id' => $point->id,
                    'thread' => $point->plot_thread_id,
                    'item' => $point->work_item_id,
                    'text' => $point->text,
                ])->values()->all(),
            'research' => $research->map(fn (ResearchItem $item): array => self::research($item, $known))->values()->all(),
            'notes' => self::notesHtml($work->plan_notes),
            'chapters' => self::chapters($items, $sources),
        ];
    }

    /**
     * The research tie prefixes (c, e, t, i) and the morph type each one is stored under.
     *
     * @return array<string, string>
     */
    public static function tieTypes(): array
    {
        return [
            'c' => self::morphClass(Character::class),
            'e' => self::morphClass(WorldEntry::class),
            't' => self::morphClass(PlotThread::class),
            'i' => self::morphClass(WorkItem::class),
        ];
    }

    /**
     * A model's alias in the morph map, or its class when the map leaves it out (the enforced map would refuse to name it).
     */
    private static function morphClass(string $model): string
    {
        return array_search($model, Relation::morphMap(), true) ?: $model;
    }

    /**
     * @return list<string>
     */
    private static function sections(Work $work): array
    {
        return match ($work->kind) {
            WorkKind::Novel => ['ch', 'plot', 'world', 'res'],
            WorkKind::Story, WorkKind::Essay, WorkKind::Collection => ['notes', 'res'],
            WorkKind::Poem => [],
        };
    }

    /**
     * The item whose words a chapter holds: a borrowed piece's own page, or the item itself.
     *
     * @param  Collection<int, WorkItem>  $items
     * @return array<int, WorkItem> keyed by item id
     */
    private static function sources(Collection $items): array
    {
        $pieceIds = $items->pluck('piece_work_id')->filter()->unique()->values();
        $own = $pieceIds->isEmpty()
            ? collect()
            : WorkItem::query()
                ->whereIn('work_id', $pieceIds)
                ->orderBy('position')
                ->get(['id', 'work_id', 'position', 'draft_text', 'live_text'])
                ->unique('work_id')
                ->keyBy('work_id');

        return $items->mapWithKeys(fn (WorkItem $item): array => [
            $item->id => $item->piece_work_id ? ($own[$item->piece_work_id] ?? $item) : $item,
        ])->all();
    }

    /**
     * Each chapter's words and title, folded for matching: accents gone, lower case.
     *
     * @param  Collection<int, WorkItem>  $items
     * @param  array<int, WorkItem>  $sources
     * @return array<int, string> keyed by chapter item id
     */
    private static function foldedChapters(Collection $items, array $sources): array
    {
        $folded = [];

        foreach ($items as $item) {
            if ($item->type === WorkItemType::Part) {
                continue;
            }

            $folded[$item->id] = mb_strtolower(Str::ascii(implode("\n", [
                $sources[$item->id]->draft_text,
                $sources[$item->id]->live_text,
                $item->title,
            ])));
        }

        return $folded;
    }

    /**
     * The chapters whose words or title say one of these names as a whole word, accents and case ignored.
     *
     * @param  list<string|null>  $names
     * @param  array<int, string>  $folded
     * @return list<int>
     */
    private static function appearsIn(array $names, array $folded): array
    {
        $forms = [];

        foreach ($names as $name) {
            $form = mb_strtolower(Str::ascii(trim((string) $name)));

            if ($form !== '') {
                $forms[$form] = preg_quote($form, '/');
            }
        }

        if ($forms === []) {
            return [];
        }

        $pattern = '/(?<![\p{L}\p{N}])(?:'.implode('|', $forms).')(?![\p{L}\p{N}])/u';

        return array_values(array_keys(array_filter($folded, fn (string $text): bool => preg_match($pattern, $text) === 1)));
    }

    /**
     * @param  Collection<int, Character>  $characters
     * @param  Collection<int, WorldGroup>  $groups
     * @param  Collection<int, PlotThread>  $threads
     * @param  Collection<int, WorkItem>  $items
     * @return array<string, true> every tie target this work still has, as 'c:ID', 'e:ID', 't:ID' or 'i:ID'
     */
    private static function knownTies(Collection $characters, Collection $groups, Collection $threads, Collection $items): array
    {
        $known = [];

        foreach ($characters as $character) {
            $known['c:'.$character->id] = true;
        }

        foreach ($groups as $group) {
            foreach ($group->entries as $entry) {
                $known['e:'.$entry->id] = true;
            }
        }

        foreach ($threads as $thread) {
            $known['t:'.$thread->id] = true;
        }

        foreach ($items as $item) {
            $known['i:'.$item->id] = true;
        }

        return $known;
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @param  array<int, string>  $folded
     * @return array<string, mixed>
     */
    private static function character(Character $character, Collection $items, array $folded): array
    {
        return [
            'id' => $character->id,
            'name' => $character->name,
            'typedAs' => $character->typed_as ?? '',
            'aliases' => array_values(array_map(strval(...), $character->aliases ?? [])),
            'role' => $character->role ?? '',
            'notes' => self::notesHtml($character->notes),
            'links' => $character->links->map(fn (CharacterLink $link): array => [
                'id' => $link->id,
                'to' => $link->other_character_id,
                'label' => $link->label ?? '',
            ])->values()->all(),
            'pov' => $items->where('pov_character_id', $character->id)->pluck('id')->values()->all(),
            'in' => self::appearsIn([$character->name, $character->typed_as, ...($character->aliases ?? [])], $folded),
        ];
    }

    /**
     * @param  array<int, string>  $folded
     * @return array<string, mixed>
     */
    private static function group(WorldGroup $group, array $folded): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'entries' => $group->entries->map(fn (WorldEntry $entry): array => [
                'id' => $entry->id,
                'name' => $entry->name,
                'typedAs' => $entry->typed_as ?? '',
                'description' => $entry->description ?? '',
                'notes' => self::notesHtml($entry->notes),
                'in' => self::appearsIn([$entry->name, $entry->typed_as], $folded),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, true>  $known
     * @return array<string, mixed>
     */
    private static function research(ResearchItem $item, array $known): array
    {
        $media = $item->media;
        $prefixes = array_flip(self::tieTypes());
        $ties = [];

        foreach ($item->ties as $tie) {
            $key = ($prefixes[$tie->tieable_type] ?? '').':'.$tie->tieable_id;

            if (isset($known[$key])) {
                $ties[] = $key;
            }
        }

        return [
            'id' => $item->id,
            'kind' => match ($item->kind) {
                ResearchKind::Link => 'link',
                ResearchKind::Image => 'img',
                ResearchKind::Note => 'note',
                ResearchKind::File => 'file',
            },
            'title' => $item->title ?? '',
            'url' => $item->url ?? '',
            'note' => $item->note ?? '',
            'src' => $media ? WorkPresenter::mediaUrl($media->disk, $media->path) : null,
            'size' => $media?->size,
            'ties' => $ties,
        ];
    }

    /**
     * Chapters and pieces in order, numbered from one; each names the part it sits under, if any.
     *
     * @param  Collection<int, WorkItem>  $items
     * @param  array<int, WorkItem>  $sources
     * @return list<array<string, mixed>>
     */
    private static function chapters(Collection $items, array $sources): array
    {
        $chapters = [];
        $part = null;
        $partNumber = 0;

        foreach ($items as $item) {
            if ($item->type === WorkItemType::Part) {
                $part = (string) $item->title;
                $partNumber++;

                continue;
            }

            $source = $sources[$item->id];
            $chapters[] = [
                'id' => $item->id,
                'number' => count($chapters) + 1,
                'title' => (string) ($item->pieceWork?->title ?? $item->title ?? ''),
                'part' => $part,
                'partNumber' => $part === null ? null : $partNumber,
                'written' => trim((string) $source->draft_text) !== '' || trim((string) $source->live_text) !== '',
            ];
        }

        return $chapters;
    }

    /**
     * Notes are kept as {"html": ...}; the seed keeps them as one plain paragraph per list item, which read the same.
     */
    private static function notesHtml(mixed $notes): string
    {
        if (is_array($notes) && isset($notes['html']) && is_string($notes['html'])) {
            return $notes['html'];
        }

        if (is_array($notes) && array_is_list($notes)) {
            return implode('', array_map(
                fn (mixed $paragraph): string => '<p>'.htmlspecialchars((string) $paragraph, ENT_QUOTES, 'UTF-8').'</p>',
                $notes,
            ));
        }

        return '';
    }
}
