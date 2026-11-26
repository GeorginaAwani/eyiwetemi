<?php

namespace App\Http\Controllers;

use App\Enums\CommentAudience;
use App\Enums\CommentKind;
use App\Http\Requests\StoreCommentRequest;
use App\Models\Comment;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\Announcer;
use App\Support\ReaderPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Posting a comment on a chapter or piece, or a reply to a comment. Threads are one level deep: a reply to a
 * reply sits under the same top comment and names the reader it answers.
 */
class CommentController extends Controller
{
    /**
     * Saves the comment, counts it on its chapter or piece and its work, and tells the reader it answers.
     */
    public function store(StoreCommentRequest $request, Announcer $announcer): JsonResponse
    {
        $replyTo = $request->replyTarget();

        $item = WorkItem::query()->released()->whereHas('work', fn ($work) => $work->listed())
            ->select(['id', 'work_id'])
            ->findOrFail($request->integer('work_item_id'));

        $comment = DB::transaction(function () use ($request, $item, $replyTo): Comment {
            $comment = Comment::query()->create([
                'user_id' => $request->user()->id,
                'work_id' => $item->work_id,
                'work_item_id' => $item->id,
                'parent_id' => $replyTo?->parent_id ?? $replyTo?->id,
                'reply_to_user_id' => $replyTo?->user_id,
                'audience' => CommentAudience::Public,
                'kind' => CommentKind::Comment,
                'status' => null,
                'body' => $request->validated('body'),
            ]);

            WorkItem::query()->whereKey($item->id)->increment('comments_count');
            Work::query()->whereKey($item->work_id)->increment('comments_count');

            return $comment;
        });

        if ($replyTo !== null) {
            $announcer->reply($comment);
        }

        $comment->load(ReaderPage::COMMENT_WITH);
        $shape = [
            ...ReaderPage::comment($comment, ReaderPage::authorPhoto()),
            'liked' => false,
            'parent' => $comment->parent_id,
        ];

        if ($replyTo === null) {
            $shape = [...$shape, 'deleted' => false, 'replies' => []];
        }

        return response()->json([
            'comment' => $shape,
            'count' => (int) WorkItem::query()->whereKey($item->id)->value('comments_count'),
        ], 201);
    }
}
