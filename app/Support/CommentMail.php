<?php

namespace App\Support;

use App\Enums\CommentKind;
use App\Enums\WorkItemType;
use App\Models\Comment;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Collection;

/**
 * What the comment emails say: the place a comment sits on ("The Ember Gate · Chapter 7", or the title alone), the
 * words a note quotes or suggests, the name a comment goes by, and the admin addresses the emails link to.
 */
class CommentMail
{
    /**
     * The comments grouped by place, the places in the order they first come up. Each place keeps its comments' order,
     * so the caller sorts them first (newest first for the digest, oldest first for a reviewer's notes).
     *
     * @param  Collection<int, Comment>  $comments  With work and workItem loaded.
     * @return list<array{title: string, comments: Collection<int, Comment>}>
     */
    public static function places(Collection $comments): array
    {
        $itemsByWork = WorkItem::query()
            ->whereIn('work_id', $comments->pluck('work_id')->unique()->all())
            ->orderBy('position')
            ->get(['id', 'work_id', 'type', 'position'])
            ->groupBy('work_id');

        return $comments
            ->groupBy(fn (Comment $comment): string => $comment->work_id.':'.$comment->work_item_id)
            ->map(fn (Collection $group): array => [
                'title' => self::title($group->first(), $itemsByWork->get($group->first()->work_id, collect())),
                'comments' => $group->values(),
            ])
            ->values()
            ->all();
    }

    /**
     * A note as the reviewers' mail gives it: the words it is about in quotes, a suggested edit, and the note itself,
     * a reply reading "Reply: ...". A line is null when the note has none.
     *
     * @return array{quote: ?string, suggestion: ?string, text: ?string}
     */
    public static function note(Comment $comment): array
    {
        $quote = $comment->anchor['quote'] ?? null;
        $edit = $comment->kind === CommentKind::Edit ? $comment->suggestion : null;

        return [
            'quote' => filled($quote) ? '“'.$quote.'”' : null,
            'suggestion' => is_array($edit) ? 'Suggested: '.($edit['deleted'] ?? '').' → '.($edit['inserted'] ?? '') : null,
            'text' => filled($comment->body) ? ($comment->parent_id === null ? $comment->body : 'Reply: '.$comment->body) : null,
        ];
    }

    /** The name a comment goes by in the digest: the username, else the name, else "Someone". */
    public static function who(?User $user): string
    {
        return $user?->username ?: ($user?->name ?: 'Someone');
    }

    /** An admin page's address, for the buttons in the comment emails. */
    public static function url(string $path): string
    {
        return 'https://'.config('eyiwetemi.admin_domain').$path;
    }

    /**
     * "The Ember Gate · Chapter 7" for a chapter or piece that has others to be told apart from, else the work's title.
     *
     * @param  Collection<int, WorkItem>  $items  The work's chapters, pieces and parts in reading order.
     */
    private static function title(Comment $comment, Collection $items): string
    {
        $work = $comment->work;
        $item = $comment->workItem;
        $numbered = $items
            ->filter(fn (WorkItem $sibling): bool => in_array($sibling->type, [WorkItemType::Chapter, WorkItemType::Piece], true))
            ->count() > 1;
        $unit = $item !== null && $numbered ? DeskPage::unitNumber($work, $item, $items) : null;

        return $unit === null ? $work->title : $work->title.' · '.ucfirst($unit);
    }
}
