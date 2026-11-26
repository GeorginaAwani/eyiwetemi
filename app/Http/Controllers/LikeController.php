<?php

namespace App\Http\Controllers;

use App\Enums\CommentAudience;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Note;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Like and unlike: a chapter or piece, a work, a note or a comment readers can see. Each keeps its own count.
 */
class LikeController extends Controller
{
    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $target = $this->target($type, $id);

        DB::transaction(function () use ($request, $type, $target): void {
            $like = Like::query()->firstOrCreate(['user_id' => $request->user()->id, 'likeable_type' => $type, 'likeable_id' => $target->getKey()]);
            if ($like->wasRecentlyCreated) {
                $target->newQuery()->whereKey($target->getKey())->increment('likes_count');
            }
        });

        return response()->json(['liked' => true, 'count' => (int) $target->newQuery()->whereKey($target->getKey())->value('likes_count')]);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $target = $this->target($type, $id);

        DB::transaction(function () use ($request, $type, $target): void {
            $gone = Like::query()->where(['user_id' => $request->user()->id, 'likeable_type' => $type, 'likeable_id' => $target->getKey()])->delete();
            if ($gone) {
                $target->newQuery()->whereKey($target->getKey())->where('likes_count', '>', 0)->decrement('likes_count');
            }
        });

        return response()->json(['liked' => false, 'count' => (int) $target->newQuery()->whereKey($target->getKey())->value('likes_count')]);
    }

    /**
     * Only things a reader can see can be liked.
     */
    private function target(string $type, int $id): Model
    {
        $query = match ($type) {
            'work' => Work::query()->listed(),
            'work_item' => WorkItem::query()->released()->whereHas('work', fn ($work) => $work->listed()),
            'note' => Note::query()->released(),
            'comment' => Comment::query()->where('audience', CommentAudience::Public)->whereNull('hidden_at')->whereHas('work', fn ($work) => $work->listed()),
            default => abort(404),
        };

        return $query->select(['id'])->findOrFail($id);
    }
}
