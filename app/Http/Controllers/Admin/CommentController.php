<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CommentAudience;
use App\Enums\CommentKind;
use App\Enums\CommentStatus;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Like;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\Announcer;
use App\Support\CommentsInbox;
use App\Support\Prose;
use App\Support\ReviewEdit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The comments inbox: readers' comments and reviewers' notes, and the writer's answers, likes, hides, status changes
 * (accepting an edit changes the draft) and seen marks, plus the two mail switches.
 */
class CommentController extends Controller
{
    /**
     * The inbox, opened on the readers' tab or the reviewers' tab (`?tab=rev`), with the works and counts each shows.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Comments', [
            'tab' => $request->query('tab') === 'rev' ? 'v' : 'r',
            'w' => $this->text($request, 'w'),
            'who' => $this->text($request, 'who'),
            'counts' => CommentsInbox::counts(),
            'works' => CommentsInbox::works(),
            'mail' => CommentsInbox::mail(),
            'me' => CommentsInbox::me($this->author($request)),
        ]);
    }

    /**
     * The readers' comments, grouped by chapter or piece, five groups a page.
     */
    public function readers(Request $request): JsonResponse
    {
        return response()->json(CommentsInbox::readerGroups(
            $this->author($request),
            $this->text($request, 'w'),
            $this->text($request, 'q'),
            max(1, (int) $request->query('page', 1)),
        ));
    }

    /**
     * Every top-level reader comment on one chapter or piece, newest first.
     */
    public function readerItem(Request $request, WorkItem $item): JsonResponse
    {
        return response()->json(CommentsInbox::itemComments($this->author($request), $item->id, $this->text($request, 'q')));
    }

    /**
     * The reviewers' notes, open and done apart.
     */
    public function reviewers(Request $request): JsonResponse
    {
        return response()->json(CommentsInbox::reviewerGroups(
            $this->text($request, 'w'),
            $this->text($request, 'who'),
            $this->text($request, 'q'),
        ));
    }

    /**
     * The writer's answer to a comment or a note. Answering a reply answers its top comment, the one the thread sits under.
     */
    public function reply(Request $request, Comment $comment): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $author = $this->author($request);

        $comment->loadMissing('parent');
        $top = $comment->parent_id === null ? $comment : ($comment->parent ?? abort(404));

        $reply = DB::transaction(function () use ($author, $comment, $top, $data): Comment {
            $reply = Comment::query()->create([
                'user_id' => $author->id,
                'work_id' => $top->work_id,
                'work_item_id' => $top->work_item_id,
                'parent_id' => $top->id,
                'reply_to_user_id' => $comment->user_id,
                'audience' => $top->audience,
                'kind' => CommentKind::Comment,
                'status' => null,
                'body' => $data['body'],
            ]);

            if ($top->audience === CommentAudience::Public) {
                WorkItem::query()->whereKey($top->work_item_id)->increment('comments_count');
                Work::query()->whereKey($top->work_id)->increment('comments_count');
            }

            CommentsInbox::markSeen([$top->id]);

            return $reply;
        });

        if ($reply->audience === CommentAudience::Public) {
            app(Announcer::class)->reply($reply);
        }

        return response()->json([
            'reply' => CommentsInbox::reply($reply->load('user:id,name,username,avatar_seed,role')),
        ], 201);
    }

    /**
     * The writer likes a reader's comment.
     */
    public function like(Request $request, Comment $comment): JsonResponse
    {
        abort_unless($comment->audience === CommentAudience::Public, 404);

        $author = $this->author($request);

        DB::transaction(function () use ($author, $comment): void {
            $like = Like::query()->firstOrCreate([
                'user_id' => $author->id,
                'likeable_type' => $comment->getMorphClass(),
                'likeable_id' => $comment->id,
            ]);

            if ($like->wasRecentlyCreated) {
                Comment::query()->whereKey($comment->id)->increment('likes_count');
            }
        });

        CommentsInbox::markSeen([$comment->id]);

        return response()->json(['likes' => $this->likes($comment), 'liked' => true]);
    }

    /**
     * The writer takes her like off a reader's comment.
     */
    public function unlike(Request $request, Comment $comment): JsonResponse
    {
        abort_unless($comment->audience === CommentAudience::Public, 404);

        $removed = Like::query()->where([
            'user_id' => $this->author($request)->id,
            'likeable_type' => $comment->getMorphClass(),
            'likeable_id' => $comment->id,
        ])->delete();

        if ($removed > 0) {
            Comment::query()->whereKey($comment->id)->where('likes_count', '>', 0)->decrement('likes_count');
        }

        return response()->json(['likes' => $this->likes($comment), 'liked' => false]);
    }

    /**
     * Hides a top-level reader comment from readers, or shows it again.
     */
    public function hide(Request $request, Comment $comment): JsonResponse
    {
        abort_unless($comment->audience === CommentAudience::Public && $comment->parent_id === null, 404);

        $request->validate(['hidden' => ['required', 'boolean']]);
        $hidden = $request->boolean('hidden');

        $comment->forceFill(['hidden_at' => $hidden ? ($comment->hidden_at ?? now()) : null])->save();
        CommentsInbox::markSeen([$comment->id]);

        return response()->json(['hidden' => $comment->hidden_at !== null]);
    }

    /**
     * Moves a reviewer's note on: accepts or rejects an edit, resolves a note, or reopens a resolved one. Accepting an
     * edit changes the chapter or piece's draft; when the text has changed since, nothing is saved.
     */
    public function status(Request $request, Comment $comment): JsonResponse
    {
        abort_unless($comment->audience === CommentAudience::Review && $comment->parent_id === null, 404);

        $data = $request->validate(['status' => ['required', Rule::in(['open', 'accepted', 'rejected', 'resolved'])], 'apply' => ['sometimes', 'boolean']]);
        /* the writing screen changes its own text and only says so */
        $apply = $request->boolean('apply', true);
        $target = CommentStatus::from($data['status']);
        $current = $comment->status;

        if ($target === $current) {
            return response()->json(['note' => CommentsInbox::note($comment)]);
        }

        if (in_array($target, [CommentStatus::Accepted, CommentStatus::Rejected], true)) {
            if ($comment->kind !== CommentKind::Edit) {
                throw ValidationException::withMessages(['status' => 'Only an edit can be accepted or rejected.']);
            }

            if ($current !== CommentStatus::Open) {
                throw ValidationException::withMessages(['status' => 'Only an open edit can be accepted or rejected.']);
            }
        }

        if ($target === CommentStatus::Open && $current !== CommentStatus::Resolved) {
            throw ValidationException::withMessages(['status' => 'Only a resolved note can be reopened.']);
        }

        $changed = DB::transaction(function () use ($comment, $target, $apply): bool {
            $comment->loadMissing('workItem');

            if ($target === CommentStatus::Accepted && $apply && ! $this->applyEdit($comment)) {
                return false;
            }

            $comment->forceFill([
                'status' => $target,
                'resolved_at' => $target === CommentStatus::Open ? null : now(),
                'seen_at' => $comment->seen_at ?? now(),
            ])->save();

            return true;
        });

        if (! $changed) {
            return response()->json(['message' => 'That line has changed.'], 409);
        }

        return response()->json(['note' => CommentsInbox::note($comment)]);
    }

    /**
     * Marks readers' comments and reviewers' notes seen, the ones in view.
     */
    public function seen(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        CommentsInbox::markSeen(array_map(intval(...), $data['ids']));

        return response()->json(['counts' => CommentsInbox::counts()]);
    }

    /**
     * Turns one of the two mails on or off: the readers' daily one, or the reviewers' at once.
     */
    public function mail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::in(['r', 'v'])],
            'on' => ['required', 'boolean'],
        ]);

        SiteSetting::put($data['key'] === 'r' ? 'mail.readers_daily' : 'mail.reviewers_now', $request->boolean('on'));

        return response()->json(['mail' => CommentsInbox::mail()]);
    }

    /**
     * Applies an edit's suggestion to its chapter or piece's draft and counts the draft as saved. False when the line
     * is no longer as the reviewer saw it.
     */
    private function applyEdit(Comment $comment): bool
    {
        $item = $comment->workItem;

        if ($item === null) {
            return false;
        }

        $doc = ReviewEdit::apply(
            $item->draft_body ?? [],
            $comment->anchor ?? [],
            (string) ($comment->suggestion['deleted'] ?? ''),
            (string) ($comment->suggestion['inserted'] ?? ''),
        );

        if ($doc === null) {
            return false;
        }

        $item->forceFill([
            'draft_body' => $doc,
            'draft_text' => Prose::text($doc),
            'draft_words' => Prose::words($doc),
            'draft_revision' => $item->draft_revision + 1,
            'draft_saved_at' => now(),
        ])->save();

        return true;
    }

    private function likes(Comment $comment): int
    {
        return (int) Comment::query()->whereKey($comment->id)->value('likes_count');
    }

    private function author(Request $request): User
    {
        return $request->user();
    }

    /**
     * A query-string value as a trimmed string, or null when it is missing or empty.
     */
    private function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
