<?php

namespace Database\Seeders;

use App\Enums\CommentAudience;
use App\Enums\CommentKind;
use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\ReadingProgress;
use App\Models\Series;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReaderSeeder extends Seeder
{
    /** Likes and comments are row-backed, so each one needs a reader account: a count is capped at the readers seeded. */
    private const MAX_LIKES = 20;

    /** The prototype's first name for each reviewer, as it appears on their marks. */
    private const REVIEWER_NAMES = [
        'Adaeze' => 'Adaeze Obi',
        'Kemi' => 'Kemi Bello',
        'Ruth' => 'Ruth Okon',
        'Dayo' => 'Dayo Bankole',
        'Chioma' => 'Chioma Eze',
    ];

    /** @var array<string, User> */
    private array $usersByName = [];

    /** @var array<string, Work> */
    private array $works = [];

    /** @var Collection<int, User> */
    private $readers;

    private User $author;

    public function run(): void
    {
        $this->author = User::query()->where('username', 'eyiwetemi')->firstOrFail();
        $this->usersByName = User::query()->get()->keyBy('name')->all();
        $this->readers = User::query()->where('role', 'reader')->orderBy('id')->get();
        $this->works = Work::query()->get()->keyBy('slug')->all();

        $this->socialAccount();
        $this->prototypeComments();
        $this->chapterThreeThread();
        $this->reviewerMarks();
        $this->chapterLikes();
        $this->follows();
        $this->readingProgress();
        $this->recount();
    }

    private function socialAccount(): void
    {
        $this->usersByName['Adaeze Okafor']->socialAccounts()->forceCreate([
            'provider' => 'google',
            'provider_user_id' => 'seed-google-adaeze',
        ]);
    }

    /**
     * The reader comments on each chapter in the prototype's admin seeds, with their replies and likes.
     */
    private function prototypeComments(): void
    {
        foreach (SeedData::R0 as $row) {
            [, $who, $slug, $chapter, $minutes, $text, $likes, $new] = $row;
            $liked = (bool) ($row[8] ?? false);
            $hidden = (bool) ($row[9] ?? false);
            $replies = $row[10] ?? [];

            $item = $this->item($this->works[$slug], $chapter);
            $author = $this->usersByName[$who];
            $created = now()->subMinutes($minutes);

            $comment = $this->comment($item, $author, $text, $created, [
                'seen_at' => $new ? null : $this->seenAt($created),
                'hidden_at' => $hidden ? $this->seenAt($created) : null,
            ]);

            $this->likes($comment, $likes, $author);

            if ($liked) {
                $comment->likes()->forceCreate(['user_id' => $this->author->id]);
            }

            foreach ($replies as $reply) {
                $replyUser = ($reply['me'] ?? false) ? $this->author : $this->usersByName[$reply['who']];
                $this->reply($comment, $replyUser, $reply['text'], null);
            }
        }
    }

    /**
     * The end-of-chapter thread on chapter 3 of The Ember Gate.
     */
    private function chapterThreeThread(): void
    {
        $item = $this->item($this->works['the-ember-gate'], 'c3');

        foreach (SeedData::COMMENTS as $entry) {
            $author = $this->usersByName[$this->displayName($entry['who'])];
            $created = $this->ago($entry['t']);

            $comment = $this->comment($item, $author, $entry['text'], $created, [
                'seen_at' => $this->seenAt($created),
            ]);

            $this->likes($comment, $entry['likes'], $author);

            if (! empty($entry['liked'])) {
                $comment->likes()->forceCreate(['user_id' => $this->author->id]);
            }

            foreach ($entry['replies'] as [$who, $replyTo, $t, $text, $likes]) {
                $replyAuthor = $who === 'Georgina' ? $this->author : $this->usersByName[$this->displayName($who)];
                $replyToUser = $replyTo !== null ? $this->usersByName[$this->displayName($replyTo)] : null;
                $reply = $this->reply($comment, $replyAuthor, $text, $replyToUser);

                $this->likes($reply, $likes, $replyAuthor);
            }
        }
    }

    /**
     * Reviewer marks anchored to a line, the suggested edits among them, and the loose notes without a mark.
     */
    private function reviewerMarks(): void
    {
        $ember = $this->works['the-ember-gate'];
        $emberSeven = $this->item($ember, 'c7');
        $starlings = $this->works['starlings-go-south-in-september'];
        $starlingsOne = $this->item($starlings, 'c1');
        $paperBoats = $this->works['paper-boats'];
        $paperBoatsThirtyThree = $this->item($paperBoats, 'c33');

        $this->review($emberSeven, $this->reviewer('Adaeze'), CommentKind::Line, 'This line. Keep it.', now()->subHour(), [
            'anchor' => $this->anchor($emberSeven, 'did not write anything in it for an hour'),
            'seen_at' => null,
        ]);

        $this->review($emberSeven, $this->reviewer('Adaeze'), CommentKind::Edit, 'Doorway. He’s on the threshold, not in the room.', now()->subHour(), [
            'anchor' => $this->anchor($emberSeven, 'from the door'),
            'suggestion' => ['deleted' => 'from the door', 'inserted' => 'from the doorway'],
            'seen_at' => null,
        ]);

        $starlingsMark = $this->review($starlingsOne, $this->reviewer('Kemi'), CommentKind::Line, 'Who is “them”? The birds or the aunties?', now()->subDay(), [
            'anchor' => $this->anchor($starlingsOne, 'though none of them knew it yet'),
            'seen_at' => $this->seenAt(now()->subDay()),
        ]);

        $this->reply($starlingsMark, $this->author, 'The aunties. I’ll make it clearer.', $this->reviewer('Kemi'));

        $this->review($paperBoatsThirtyThree, $this->reviewer('Adaeze'), CommentKind::Line, 'This line. Keep it.', now()->subDays(2), [
            'anchor' => $this->anchor($paperBoatsThirtyThree, 'like small boats that had given up'),
            'seen_at' => $this->seenAt(now()->subDays(2)),
        ]);

        $this->review($paperBoatsThirtyThree, $this->reviewer('Kemi'), CommentKind::Edit, '“Nobody’s name” feels lonelier.', now()->subDay(), [
            'anchor' => $this->anchor($paperBoatsThirtyThree, 'no name'),
            'suggestion' => ['deleted' => 'no name', 'inserted' => 'nobody’s name'],
            'seen_at' => $this->seenAt(now()->subDay()),
        ]);

        $this->review($emberSeven, $this->reviewer('Adaeze'), CommentKind::Comment, 'The middle drags a little after the rain starts. Could her brother come in earlier?', now()->subMinutes(62), [
            'seen_at' => null,
        ]);

        $this->review(null, $this->reviewer('Ruth'), CommentKind::Book, 'The opening chapter is the strongest start in the series so far.', now()->subMinutes(2900), [
            'work' => $this->works['harbour-songs'],
            'seen_at' => $this->seenAt(now()->subMinutes(2900)),
        ]);

        $midnightTwelve = $this->item($this->works['midnight-poems'], 'c12');
        $resolvedAt = now()->subMinutes(5800);
        $this->review($midnightTwelve, $this->reviewer('Dayo'), CommentKind::Comment, 'Best last line in the collection.', $resolvedAt, [
            'status' => CommentStatus::Resolved,
            'resolved_at' => $resolvedAt->copy()->addDay(),
            'seen_at' => $this->seenAt($resolvedAt),
        ]);

        $saltThree = $this->item($this->works['salt-season'], 'c3');
        $resolvedAt = now()->subMinutes(40000);
        $chioma = $this->review($saltThree, $this->reviewer('Chioma'), CommentKind::Comment, 'Love how the salt flats come back at the end.', $resolvedAt, [
            'status' => CommentStatus::Resolved,
            'resolved_at' => $resolvedAt->copy()->addDay(),
            'seen_at' => $this->seenAt($resolvedAt),
        ]);

        $this->reply($chioma, $this->author, 'Thank you!', $this->reviewer('Chioma'));
    }

    /**
     * Admin likes and reader likes on chapters and pieces, with a few on the signed-in reader's own reading.
     */
    private function chapterLikes(): void
    {
        $adaeze = $this->usersByName['Adaeze Okafor'];

        foreach ([['the-ember-gate', 'c3'], ['the-ember-gate', 'c5'], ['paper-boats', 'c30'], ['paper-boats', 'c31'], ['harmattan', 'body'], ['night-bus', 'body']] as [$slug, $chapter]) {
            $this->item($this->works[$slug], $chapter)->likes()->forceCreate(['user_id' => $adaeze->id]);
        }
    }

    private function follows(): void
    {
        $adaeze = $this->usersByName['Adaeze Okafor'];

        foreach (['the-ember-gate', 'paper-boats', 'starlings-go-south-in-september', 'salt-season', 'rain-stories', 'midnight-poems', 'harbour-songs'] as $slug) {
            $this->works[$slug]->follows()->forceCreate(['user_id' => $adaeze->id]);
        }

        Series::query()->where('slug', 'the-harbour-books')->firstOrFail()
            ->follows()->forceCreate(['user_id' => $adaeze->id]);

        $this->works['the-ember-gate']->follows()->forceCreate(['user_id' => $this->usersByName['Tolu Ade']->id]);
        $this->works['paper-boats']->follows()->forceCreate(['user_id' => $this->usersByName['Chiamaka']->id]);
        $this->works['salt-season']->follows()->forceCreate(['user_id' => $this->usersByName['Bisi']->id]);
    }

    /**
     * Where the signed-in reader has got to: chapters before the current one are finished.
     */
    private function readingProgress(): void
    {
        $adaeze = $this->usersByName['Adaeze Okafor'];

        $steps = [
            ['the-ember-gate', 'c1', 100, 6],
            ['the-ember-gate', 'c2', 100, 5],
            ['the-ember-gate', 'c3', 100, 4],
            ['the-ember-gate', 'c4', 100, 3],
            ['the-ember-gate', 'c5', 40, null],
            ['paper-boats', 'c30', 100, 2],
            ['paper-boats', 'c31', 35, null],
            ['starlings-go-south-in-september', 'c1', 100, 9],
            ['salt-season', 'c12', 100, 30],
            ['rain-stories', 'c3', 20, null],
        ];

        foreach ($steps as [$slug, $chapter, $percent, $daysAgo]) {
            $item = $this->item($this->works[$slug], $chapter);

            ReadingProgress::forceCreate([
                'user_id' => $adaeze->id,
                'work_item_id' => $item->id,
                'block_id' => $this->firstBlockId($item),
                'percent' => $percent,
                'finished_at' => $daysAgo !== null ? now()->subDays($daysAgo) : null,
            ]);
        }
    }

    /**
     * Counters are recounted from the rows just written, so they always match.
     */
    private function recount(): void
    {
        foreach (Comment::query()->get() as $comment) {
            $comment->forceFill(['likes_count' => $comment->likes()->count()])->save();
        }

        foreach (WorkItem::query()->get() as $item) {
            $item->forceFill([
                'likes_count' => $item->likes()->count(),
                'comments_count' => $item->comments()->where('audience', CommentAudience::Public)->count(),
            ])->save();
        }

        foreach (Work::query()->get() as $work) {
            $work->forceFill([
                'comments_count' => Comment::query()
                    ->where('work_id', $work->id)
                    ->where('audience', CommentAudience::Public)
                    ->count(),
            ])->save();
        }
    }

    private function comment(WorkItem $item, User $user, string $body, Carbon $created, array $extra = []): Comment
    {
        return Comment::forceCreate(array_merge([
            'user_id' => $user->id,
            'work_id' => $item->work_id,
            'work_item_id' => $item->id,
            'parent_id' => null,
            'reply_to_user_id' => null,
            'audience' => CommentAudience::Public,
            'kind' => CommentKind::Comment,
            'body' => $body,
            'created_at' => $created,
            'updated_at' => $created,
        ], $extra));
    }

    /**
     * A reviewer mark or loose note. Marks sit on a chapter; a book note has no chapter.
     *
     * @param  array<string, mixed>  $extra
     */
    private function review(?WorkItem $item, User $user, CommentKind $kind, string $body, Carbon $created, array $extra = []): Comment
    {
        $work = $extra['work'] ?? null;
        unset($extra['work']);

        return Comment::forceCreate(array_merge([
            'user_id' => $user->id,
            'work_id' => $item?->work_id ?? $work->id,
            'work_item_id' => $item?->id,
            'parent_id' => null,
            'reply_to_user_id' => null,
            'audience' => CommentAudience::Review,
            'kind' => $kind,
            'status' => CommentStatus::Open,
            'body' => $body,
            'created_at' => $created,
            'updated_at' => $created,
        ], $extra));
    }

    private function reply(Comment $parent, User $user, string $body, ?User $replyTo): Comment
    {
        $created = $this->capped($parent->created_at->copy()->addMinutes(30));

        return Comment::forceCreate([
            'user_id' => $user->id,
            'work_id' => $parent->work_id,
            'work_item_id' => $parent->work_item_id,
            'parent_id' => $parent->id,
            'reply_to_user_id' => $replyTo?->id,
            'audience' => $parent->audience,
            'kind' => CommentKind::Comment,
            'body' => $body,
            'created_at' => $created,
            'updated_at' => $created,
        ]);
    }

    /**
     * Likes from readers other than the liker's own account, capped at the number of readers.
     */
    private function likes(Model $likeable, int $count, User $exclude): void
    {
        $likers = $this->readers
            ->reject(fn (User $reader): bool => $reader->is($exclude))
            ->take(min($count, self::MAX_LIKES));

        foreach ($likers as $liker) {
            $likeable->likes()->forceCreate(['user_id' => $liker->id]);
        }
    }

    private function item(Work $work, string $key): WorkItem
    {
        $items = $work->items()->where('type', '!=', 'part')->get()->values();

        return $key === 'body' ? $items->first() : $items[(int) substr($key, 1) - 1];
    }

    private function reviewer(string $prototypeName): User
    {
        return $this->usersByName[self::REVIEWER_NAMES[$prototypeName]];
    }

    /**
     * The prototype's short names mapped to the account names seeded in UserSeeder.
     */
    private function displayName(string $prototypeName): string
    {
        return match ($prototypeName) {
            'Tolu' => 'Tolu Ade',
            'Georgina' => $this->author->name,
            default => $prototypeName,
        };
    }

    private function anchor(WorkItem $item, string $quote): array
    {
        return ProseBody::anchor($item->draft_body, $quote);
    }

    private function firstBlockId(WorkItem $item): ?string
    {
        $document = $item->live_body ?? $item->draft_body;

        return $document === null ? null : $document['content'][0]['attrs']['id'];
    }

    /**
     * "2 d", "5 h", "20 h" and "3 weeks" from the prototype, as a moment in the past.
     */
    private function ago(string $relative): Carbon
    {
        return match (true) {
            preg_match('/^(\d+) min/', $relative, $match) === 1 => now()->subMinutes((int) $match[1]),
            preg_match('/^(\d+) h/', $relative, $match) === 1 => now()->subHours((int) $match[1]),
            preg_match('/^(\d+) d/', $relative, $match) === 1 => now()->subDays((int) $match[1]),
            preg_match('/^(\d+) weeks?/', $relative, $match) === 1 => now()->subWeeks((int) $match[1]),
            default => now()->subDay(),
        };
    }

    /**
     * A moment shortly after something was posted, never in the future.
     */
    private function seenAt(Carbon $created): Carbon
    {
        return $this->capped($created->copy()->addMinutes(30));
    }

    private function capped(Carbon $at): Carbon
    {
        return $at->greaterThan(now()) ? now() : $at;
    }
}
