<?php

namespace Database\Seeders;

use App\Enums\ResearchKind;
use App\Models\Character;
use App\Models\CharacterLink;
use App\Models\Media;
use App\Models\PlotPoint;
use App\Models\PlotThread;
use App\Models\ResearchItem;
use App\Models\ResearchTie;
use App\Models\SweepName;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WorldEntry;
use App\Models\WorldGroup;
use Illuminate\Database\Seeder;

class PlanningSeeder extends Seeder
{
    /** Sweep names the prototype's writing screen starts with, by work slug. */
    private const SWEEP_NAMES = [
        'the-ember-gate' => [['Adaeze', 'Adaeze'], ['Mr Ikiriko', 'Mr Ikiriko']],
        'starlings-go-south-in-september' => [['Ruth', 'Ruth'], ['Ada', 'Ada']],
        'paper-boats' => [['Ifeoma', 'Ifeoma'], ['Dayo', 'Dayo'], ['Ebi', 'Ebi']],
        'under-the-plum-rain' => [['Xu Wei', 'Xú Wēi'], ['Lin Yue', 'Lín Yuè'], ['Li', 'Lǐ|Lí']],
    ];

    public function run(): void
    {
        $author = User::query()->where('username', 'eyiwetemi')->firstOrFail();

        foreach (['under-the-plum-rain', 'paper-boats'] as $slug) {
            $work = Work::query()->where('slug', $slug)->firstOrFail();
            $plan = SeedData::PLAN_PSEED[$slug];

            $characters = $this->characters($work, $plan['chars']);
            $entries = $this->world($work, $plan['groups']);
            $threads = $this->threads($work, $plan['threads']);
            $this->points($work, $plan['points'], $threads);
            $this->research($work, $plan['res'], $characters, $entries, $author);
        }

        foreach (self::SWEEP_NAMES as $slug => $names) {
            $work = Work::query()->where('slug', $slug)->firstOrFail();

            foreach ($names as $position => [$plain, $accented]) {
                SweepName::forceCreate([
                    'work_id' => $work->id,
                    'plain' => $plain,
                    'readings' => explode('|', $accented),
                    'position' => $position + 1,
                ]);
            }
        }

        $this->pointsOfView();
    }

    /**
     * @param  list<array<string, mixed>>  $people
     * @return array<string, Character> keyed by the prototype's character key
     */
    private function characters(Work $work, array $people): array
    {
        $characters = [];

        foreach ($people as $position => $person) {
            $characters[$person['id']] = Character::forceCreate([
                'work_id' => $work->id,
                'name' => $person['n'],
                'typed_as' => $person['t'] !== '' ? $person['t'] : null,
                'aliases' => $person['aka'] !== [] ? $person['aka'] : null,
                'role' => $person['role'] !== '' ? $person['role'] : null,
                'notes' => $person['notes'] !== '' ? $this->paragraphs($person['notes']) : null,
                'position' => $position + 1,
            ]);
        }

        foreach ($people as $person) {
            foreach ($person['rels'] as $relation) {
                CharacterLink::forceCreate([
                    'character_id' => $characters[$person['id']]->id,
                    'other_character_id' => $characters[$relation['to']]->id,
                    'label' => $relation['lb'],
                ]);
            }
        }

        return $characters;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, WorldEntry> keyed by the prototype's entry key
     */
    private function world(Work $work, array $groups): array
    {
        $entries = [];

        foreach ($groups as $groupPosition => $group) {
            $worldGroup = WorldGroup::forceCreate([
                'work_id' => $work->id,
                'name' => $group['n'],
                'position' => $groupPosition + 1,
            ]);

            foreach ($group['items'] as $entryPosition => $entry) {
                $entries[$entry['id']] = WorldEntry::forceCreate([
                    'world_group_id' => $worldGroup->id,
                    'name' => $entry['n'],
                    'typed_as' => $entry['t'] !== '' ? $entry['t'] : null,
                    'description' => $entry['d'] !== '' ? $entry['d'] : null,
                    'notes' => $entry['notes'] !== '' ? $this->paragraphs($entry['notes']) : null,
                    'position' => $entryPosition + 1,
                ]);
            }
        }

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $threads
     * @return array<string, PlotThread> keyed by the prototype's thread key
     */
    private function threads(Work $work, array $threads): array
    {
        $created = [];

        foreach ($threads as $position => $thread) {
            $created[$thread['id']] = PlotThread::forceCreate([
                'work_id' => $work->id,
                'name' => $thread['n'],
                'colour' => $thread['c'],
                'position' => $position + 1,
            ]);
        }

        return $created;
    }

    /**
     * @param  list<array<string, mixed>>  $points
     * @param  array<string, PlotThread>  $threads
     */
    private function points(Work $work, array $points, array $threads): void
    {
        foreach ($points as $position => $point) {
            PlotPoint::forceCreate([
                'plot_thread_id' => $threads[$point['th']]->id,
                'work_item_id' => $point['ch'] !== null ? $this->chapter($work, $point['ch'])->id : null,
                'text' => $point['x'],
                'position' => $position + 1,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, Character>  $characters
     * @param  array<string, WorldEntry>  $entries
     */
    private function research(Work $work, array $items, array $characters, array $entries, User $author): void
    {
        foreach ($items as $position => $item) {
            $research = ResearchItem::forceCreate([
                'work_id' => $work->id,
                'kind' => ResearchKind::from($item['k'] === 'img' ? 'image' : $item['k']),
                'title' => $item['t'],
                'url' => $item['url'] ?? null,
                'note' => ($item['note'] ?? '') !== '' ? $item['note'] : null,
                'media_id' => $this->researchMedia($item, $author)?->id,
                'position' => $position + 1,
            ]);

            foreach ($item['ties'] as $tie) {
                [$prefix, $key] = explode(':', $tie, 2);

                $target = match ($prefix) {
                    'ch' => $this->chapter($work, $key),
                    'c' => $characters[$key],
                    'e' => $entries[$key],
                };

                ResearchTie::forceCreate([
                    'research_item_id' => $research->id,
                    'tieable_type' => $target->getMorphClass(),
                    'tieable_id' => $target->getKey(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function researchMedia(array $item, User $author): ?Media
    {
        if ($item['k'] === 'img') {
            return Media::forceCreate([
                'disk' => 'public',
                'path' => 'seed/research/'.basename($item['src']),
                'original_name' => basename($item['src']),
                'mime' => 'image/webp',
                'size' => 0,
                'uploaded_by' => $author->id,
            ]);
        }

        if ($item['k'] === 'file') {
            return Media::forceCreate([
                'disk' => 'public',
                'path' => 'seed/research/'.$item['t'],
                'original_name' => $item['t'],
                'mime' => 'application/pdf',
                'size' => $item['size'],
                'uploaded_by' => $author->id,
            ]);
        }

        return null;
    }

    /**
     * Points of view: Paper Boats gives odd chapters to Ifeoma and even chapters to Dayo; Under the Plum Rain
     * alternates Xu Wei and Lin Yue the same way.
     */
    private function pointsOfView(): void
    {
        $paperBoats = Work::query()->where('slug', 'paper-boats')->firstOrFail();
        $ifeoma = Character::query()->where('work_id', $paperBoats->id)->where('name', 'Ifeoma')->firstOrFail();
        $dayo = Character::query()->where('work_id', $paperBoats->id)->where('name', 'Dayo')->firstOrFail();

        foreach ($this->chapters($paperBoats) as $number => $chapter) {
            $chapter->forceFill(['pov_character_id' => $number % 2 === 1 ? $ifeoma->id : $dayo->id])->save();
        }

        $plumRain = Work::query()->where('slug', 'under-the-plum-rain')->firstOrFail();
        $xuWei = Character::query()->where('work_id', $plumRain->id)->where('typed_as', 'Xu Wei')->firstOrFail();
        $linYue = Character::query()->where('work_id', $plumRain->id)->where('typed_as', 'Lin Yue')->firstOrFail();

        foreach ($this->chapters($plumRain) as $number => $chapter) {
            $chapter->forceFill(['pov_character_id' => $number % 2 === 1 ? $xuWei->id : $linYue->id])->save();
        }
    }

    /**
     * @return array<int, WorkItem> chapter items by chapter number, from 1
     */
    private function chapters(Work $work): array
    {
        $chapters = [];

        foreach ($work->items()->where('type', '!=', 'part')->get() as $index => $item) {
            $chapters[$index + 1] = $item;
        }

        return $chapters;
    }

    /**
     * @param  string  $key  "c7" for chapter 7 (the prototype's chapter key)
     */
    private function chapter(Work $work, string $key): WorkItem
    {
        return $this->chapters($work)[(int) substr($key, 1)];
    }

    /**
     * @return list<string>
     */
    private function paragraphs(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n\n", $text)),
            fn (string $part): bool => $part !== '',
        ));
    }
}
