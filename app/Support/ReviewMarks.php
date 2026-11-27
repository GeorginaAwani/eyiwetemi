<?php

namespace App\Support;

use App\Enums\CommentAudience;
use App\Enums\CommentKind;
use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Closure;
use Illuminate\Support\Str;

/**
 * A reviewer's marks on a chapter or piece: their open line notes and suggested edits, with the replies to them,
 * placed on the paragraph text the reader draws. Positions are UTF-16 offsets into the paragraph's runs text, the way
 * the browser counts them; a mark is only placed while its quote is still in that paragraph.
 */
class ReviewMarks
{
    /**
     * The viewer's open marks on a chapter or piece, for the reader. Null unless the viewer is one of the work's
     * reviewers: authors and everyone else get none.
     *
     * @param  array<string, mixed>|null  $body  the live body the reader draws for this chapter or piece
     * @return array{me: array{name: string, seed: string}, marks: list<array<string, mixed>>}|null
     */
    public static function forReader(Work $work, WorkItem $item, ?array $body): ?array
    {
        $viewer = auth()->user();

        if (! $viewer instanceof User || $viewer->isAuthor() || ! $work->reviewers()->whereKey($viewer->id)->exists()) {
            return null;
        }

        $authorPhoto = ReaderPage::authorPhoto();
        $notes = Comment::query()
            ->with(self::withMark())
            ->where('work_item_id', $item->id)
            ->where('audience', CommentAudience::Review)
            ->whereNull('parent_id')
            ->where('user_id', $viewer->id)
            ->whereIn('kind', [CommentKind::Line, CommentKind::Edit])
            ->where('status', CommentStatus::Open)
            ->oldest('id')
            ->get();

        return [
            'me' => ['name' => (string) $viewer->name, 'seed' => (string) $viewer->avatar_seed],
            'marks' => $notes
                ->map(fn (Comment $note): ?array => self::mark($note, $body, $viewer->id, $authorPhoto))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * One mark as the reader draws it, placed in its paragraph's text. Null when the paragraph or the quote in it is
     * no longer there.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|null
     */
    public static function mark(Comment $note, ?array $body, int $viewerId, ?string $authorPhoto): ?array
    {
        $note->loadMissing(self::withMark());

        $anchor = $note->anchor ?? [];
        $blockId = (string) ($anchor['block_id'] ?? '');
        $quote = (string) ($anchor['quote'] ?? '');
        $text = self::paragraphText($body, $blockId);

        if ($text === null || $quote === '') {
            return null;
        }

        $at = ReviewEdit::locate($text, $quote, (int) ($anchor['start'] ?? 0));

        if ($at === null) {
            return null;
        }

        $start = Doc::utf16Length(mb_substr($text, 0, $at));

        return [
            'id' => $note->id,
            'kind' => $note->kind->value,
            'block' => $blockId,
            'start' => $start,
            'end' => $start + Doc::utf16Length($quote),
            'ins' => $note->kind === CommentKind::Edit ? (string) ($note->suggestion['inserted'] ?? '') : '',
            'text' => (string) $note->body,
            'at' => ReaderPage::ago($note->created_at),
            'replies' => $note->replies
                ->map(fn (Comment $reply): array => self::reply($reply, $viewerId, $authorPhoto))
                ->values()
                ->all(),
        ];
    }

    /**
     * A reply as the reader draws it. The writer's name is her first name and she has her photo; "mine" is the
     * viewer's own reply.
     *
     * @return array{id: int, mine: bool, author: bool, name: string, seed: string, photo: string|null, at: string, text: string}
     */
    public static function reply(Comment $reply, int $viewerId, ?string $authorPhoto): array
    {
        $reply->loadMissing('user:id,name,avatar_seed,role');

        $user = $reply->user;
        $isAuthor = $user?->isAuthor() ?? false;

        return [
            'id' => $reply->id,
            'mine' => (int) $reply->user_id === $viewerId,
            'author' => $isAuthor,
            'name' => $isAuthor ? Str::before((string) $user?->name, ' ') : (string) $user?->name,
            'seed' => (string) $user?->avatar_seed,
            'photo' => $isAuthor ? $authorPhoto : null,
            'at' => ReaderPage::ago($reply->created_at),
            'text' => (string) $reply->body,
        ];
    }

    /**
     * The text of the top-level paragraph with this id, as the reader's runs give it: a hard break is a new line and a
     * footnote is nothing. Null when the body has no such paragraph.
     *
     * @param  array<string, mixed>|null  $body
     */
    public static function paragraphText(?array $body, string $blockId): ?string
    {
        if ($blockId === '') {
            return null;
        }

        foreach ($body['content'] ?? [] as $node) {
            if (($node['type'] ?? '') === 'paragraph' && (string) ($node['attrs']['id'] ?? '') === $blockId) {
                return implode('', array_column(Doc::runs($node), 'text'));
            }
        }

        return null;
    }

    /**
     * What a mark loads: its writer, and each reply with its writer.
     *
     * @return array<int|string, string|Closure>
     */
    private static function withMark(): array
    {
        return [
            'user:id,name,avatar_seed,role',
            'replies' => fn ($replies) => $replies->with('user:id,name,avatar_seed,role')->oldest('id'),
        ];
    }
}
