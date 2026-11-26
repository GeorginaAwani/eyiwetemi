<?php

namespace App\Support;

use App\Models\Note;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Notes: her short posts, newest first, under a heading per month.
 * The page opens with about a dozen (always whole months); "Older notes" brings the next batch.
 */
class NotesPage
{
    public const PAGE = 12;

    /**
     * @return array{months: list<array<string, mixed>>, olderAfter: int|null}
     */
    public function toArray(): array
    {
        return $this->batch(null);
    }

    /**
     * The batch after a note already on the page.
     *
     * @return array{months: list<array<string, mixed>>, olderAfter: int|null}
     */
    public function after(int $id): array
    {
        $cursor = $this->released()->find($id);

        return $cursor === null ? ['months' => [], 'olderAfter' => null] : $this->batch($cursor);
    }

    /**
     * @return array{months: list<array<string, mixed>>, olderAfter: int|null}
     */
    private function batch(?Note $cursor): array
    {
        $notes = $this->olderThan($cursor)->limit(self::PAGE)->get();

        if ($notes->count() === self::PAGE) {
            $last = $notes->last();
            $monthStart = $last->published_at->copy()->setTimezone($this->zone())->startOfMonth();
            $notes = $notes->concat($this->olderThan($last)->where('published_at', '>=', $monthStart)->get());
        }

        $last = $notes->last();
        $hasOlder = $last !== null && $this->olderThan($last)->exists();

        return [
            'months' => $this->months($notes),
            'olderAfter' => $hasOlder ? $last->id : null,
        ];
    }

    /**
     * Released notes older than the given one (newest first), with what a note shows.
     *
     * @return Builder<Note>
     */
    private function olderThan(?Note $cursor): Builder
    {
        return $this->released()
            ->select(['id', 'live_body', 'media_id', 'work_id', 'published_at'])
            ->with([
                'media:id,disk,path',
                'work' => fn ($query) => $query->listed()->select(['id', 'slug', 'title', 'kind', 'visibility', 'status', 'first_published_at']),
            ])
            ->withCount('likes')
            ->when($cursor, fn (Builder $query) => $query->where(function (Builder $query) use ($cursor): void {
                $query->where('published_at', '<', $cursor->published_at)
                    ->orWhere(fn (Builder $query) => $query->where('published_at', $cursor->published_at)->where('id', '<', $cursor->id));
            }))
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /**
     * @return Builder<Note>
     */
    private function released(): Builder
    {
        return Note::query()->released();
    }

    /**
     * @param  Collection<int, Note>  $notes
     * @return list<array<string, mixed>>
     */
    private function months(Collection $notes): array
    {
        return $notes
            ->groupBy(fn (Note $note): string => $note->published_at->copy()->setTimezone($this->zone())->format('Y-m'))
            ->map(function (Collection $group, string $key): array {
                $first = $group->first()->published_at->copy()->setTimezone($this->zone());

                return [
                    'key' => $key,
                    'name' => $first->format('F'),
                    'year' => $first->year,
                    'notes' => $group->map(fn (Note $note): array => $this->note($note))->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function note(Note $note): array
    {
        $work = $note->work;

        return [
            'id' => $note->id,
            'day' => Reading::day($note->published_at),
            'paragraphs' => Doc::paragraphs($note->live_body),
            'picture' => $note->media ? WorkPresenter::mediaUrl($note->media->disk, $note->media->path) : null,
            'work' => $work instanceof Work ? ['title' => $work->title, 'href' => WorkPresenter::href($work)] : null,
            'likes' => $note->likes_count,
        ];
    }

    private function zone(): string
    {
        return (string) config('eyiwetemi.timezone');
    }
}
