<?php

namespace App\Http\Controllers;

use App\Models\Follow;
use App\Models\Series;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Follow and unfollow a work or a series, so new chapters and notes reach you.
 */
class FollowController extends Controller
{
    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $this->check($type, $id);
        Follow::query()->firstOrCreate(['user_id' => $request->user()->id, 'followable_type' => $type, 'followable_id' => $id]);

        return response()->json(['following' => true]);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $this->check($type, $id);
        Follow::query()->where(['user_id' => $request->user()->id, 'followable_type' => $type, 'followable_id' => $id])->delete();

        return response()->json(['following' => false]);
    }

    private function check(string $type, int $id): void
    {
        $exists = match ($type) {
            'work' => Work::query()->listed()->whereKey($id)->exists(),
            'series' => Series::query()->whereKey($id)->exists(),
            default => false,
        };

        abort_unless($exists, 404);
    }
}
