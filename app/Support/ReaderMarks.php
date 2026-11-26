<?php

namespace App\Support;

use App\Models\Follow;
use App\Models\Like;
use Illuminate\Support\Collection;

/**
 * What the signed-in reader has liked or followed, for the page props. Guests have none, and no query runs.
 * A page builder asks once for every id it shows, so a page costs one query, not one per row.
 */
class ReaderMarks
{
    /**
     * The ids, of the given kind, that the signed-in reader has liked: work_item, work, note or comment.
     *
     * @param  int|iterable<int, int|string|null>  $ids
     * @return Collection<int, int>
     */
    public static function liked(string $type, int|iterable $ids): Collection
    {
        $ids = self::ids($ids);
        $userId = auth()->id();

        if ($userId === null || $ids->isEmpty()) {
            return collect();
        }

        return Like::query()
            ->where('user_id', $userId)
            ->where('likeable_type', $type)
            ->whereIn('likeable_id', $ids)
            ->pluck('likeable_id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * The ids, of the given kind, that the signed-in reader follows: work or series.
     *
     * @param  int|iterable<int, int|string|null>  $ids
     * @return Collection<int, int>
     */
    public static function followed(string $type, int|iterable $ids): Collection
    {
        $ids = self::ids($ids);
        $userId = auth()->id();

        if ($userId === null || $ids->isEmpty()) {
            return collect();
        }

        return Follow::query()
            ->where('user_id', $userId)
            ->where('followable_type', $type)
            ->whereIn('followable_id', $ids)
            ->pluck('followable_id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * @param  int|iterable<int, int|string|null>  $ids
     * @return Collection<int, int>
     */
    private static function ids(int|iterable $ids): Collection
    {
        return collect(is_iterable($ids) ? $ids : [$ids])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
    }
}
