<?php

namespace App\Http\Controllers;

use App\Enums\CommentAudience;
use App\Enums\CommentKind;
use App\Enums\CommentStatus;
use App\Http\Requests\StoreReviewMarkRequest;
use App\Http\Requests\StoreReviewReplyRequest;
use App\Models\Comment;
use App\Models\WorkItem;
use App\Support\Doc;
use App\Support\ReaderPage;
use App\Support\ReviewMarks;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A reviewer's marks on a draft: a line note or a suggested edit on the text of a paragraph, and replies to their own
 * marks. Only the work's reviewers can mark it.
 */
class ReviewMarkController extends Controller
{
    /**
     * Saves a mark on the text the reviewer selected. The selection must still be as they saw it: the characters from
     * their start to their end, in the paragraph, are their quote.
     */
    public function store(StoreReviewMarkRequest $request): JsonResponse
    {
        $user = $request->user();
        $item = WorkItem::query()->released()->with('work')->findOrFail($request->integer('item'));

        Gate::authorize('view', $item->work);
        abort_unless($item->work->reviewers()->whereKey($user->id)->exists(), 403);

        $body = ReaderPage::textItem($item)?->live_body;
        $block = $request->string('block')->toString();
        $quote = $request->string('quote')->toString();
        $text = ReviewMarks::paragraphText($body, $block);
        $start = $text === null ? null : Doc::codePointIndex($text, $request->integer('start'));
        $end = $text === null ? null : Doc::codePointIndex($text, $request->integer('end'));

        if ($text === null || $start === null || $end === null || $end <= $start || mb_substr($text, $start, $end - $start) !== $quote) {
            throw ValidationException::withMessages(['quote' => 'That line has changed.']);
        }

        $kind = CommentKind::from($request->string('kind')->toString());
        $note = $request->string('body')->toString();

        $comment = Comment::query()->create([
            'user_id' => $user->id,
            'work_id' => $item->work_id,
            'work_item_id' => $item->id,
            'audience' => CommentAudience::Review,
            'kind' => $kind,
            'status' => CommentStatus::Open,
            'anchor' => ['block_id' => $block, 'start' => $start, 'end' => $end, 'quote' => $quote],
            'suggestion' => $kind === CommentKind::Edit ? ['deleted' => $quote, 'inserted' => $request->string('inserted')->toString()] : null,
            'body' => $note === '' ? null : $note,
        ]);

        return response()->json([
            'mark' => ReviewMarks::mark($comment, $body, $user->id, ReaderPage::authorPhoto()),
        ], 201);
    }

    /**
     * Replies to one of the reviewer's own marks.
     */
    public function reply(StoreReviewReplyRequest $request, Comment $comment): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $comment->audience === CommentAudience::Review && $comment->parent_id === null && (int) $comment->user_id === $user->id,
            404,
        );
        /* a reviewer taken off the work (or a work they can no longer see) takes no more replies */
        $work = $comment->work;
        abort_unless($work !== null && Gate::allows('view', $work) && $work->reviewers()->whereKey($user->id)->exists(), 403);

        $reply = Comment::query()->create([
            'user_id' => $user->id,
            'work_id' => $comment->work_id,
            'work_item_id' => $comment->work_item_id,
            'parent_id' => $comment->id,
            'audience' => CommentAudience::Review,
            'kind' => CommentKind::Comment,
            'status' => null,
            'body' => $request->string('body')->toString(),
        ]);

        return response()->json([
            'reply' => ReviewMarks::reply($reply, $user->id, ReaderPage::authorPhoto()),
        ], 201);
    }
}
