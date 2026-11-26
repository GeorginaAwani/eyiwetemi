<?php

namespace App\Support;

use App\Models\SweepName;
use App\Models\Work;
use App\Models\WorldEntry;
use Illuminate\Support\Str;

/**
 * The names Sweep looks for: plain as she types them while writing, and the forms with their marks. The Plan's
 * characters and places join by themselves (their "typed as" → their name); she can add others by hand.
 * A name with two readings (Li → Lǐ or Lí) is picked each time it's found, never replaced on its own.
 */
class SweepNames
{
    /**
     * @return list<array{id: int|null, plain: string, readings: list<string>, from: string|null}>
     */
    public static function list(Work $work): array
    {
        $names = [];

        foreach ($work->characters()->orderBy('position')->orderBy('id')->get() as $character) {
            self::add($names, $character->typed_as ?: self::plain($character->name), [$character->name], 'c:'.$character->id);
        }

        $entries = WorldEntry::query()->whereIn('world_group_id', $work->worldGroups()->pluck('id'))->orderBy('position')->orderBy('id')->get();
        foreach ($entries as $entry) {
            self::add($names, $entry->typed_as ?: self::plain($entry->name), [$entry->name], 'e:'.$entry->id);
        }

        foreach (SweepName::query()->where('work_id', $work->id)->orderBy('position')->orderBy('id')->get() as $row) {
            self::add($names, $row->plain, array_values(array_filter((array) $row->readings)), null, $row->id);
        }

        return array_values($names);
    }

    /**
     * @param  array<string, array<string, mixed>>  $names
     * @param  list<string>  $readings
     */
    private static function add(array &$names, string $plain, array $readings, ?string $from, ?int $id = null): void
    {
        $plain = trim($plain);
        $key = Str::lower($plain);

        if ($plain === '' || isset($names[$key])) {
            return;
        }

        $names[$key] = ['id' => $id, 'plain' => $plain, 'readings' => $readings ?: [$plain], 'from' => $from];
    }

    public static function plain(string $name): string
    {
        return (string) \Normalizer::normalize(preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($name, \Normalizer::FORM_D)), \Normalizer::FORM_C);
    }
}
