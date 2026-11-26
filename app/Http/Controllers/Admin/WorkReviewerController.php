<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\Visibility;
use App\Http\Controllers\Controller;
use App\Models\Work;
use App\Support\WorksAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A work's Reviewers field: give one of her reviewers this work, or take it away (at once, no email; their comments stay).
 * Giving a Private work a reviewer opens it to reviewers.
 */
class WorkReviewerController extends Controller
{
    public function store(Request $request, Work $work): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', UserRole::Reviewer->value)],
        ]);

        $work->reviewers()->syncWithoutDetaching([$data['user_id']]);

        if ($work->visibility === Visibility::Private) {
            $work->forceFill(['visibility' => Visibility::Reviewers])->save();
        }

        return $this->answer($work);
    }

    public function destroy(Work $work, string $kind, int $id): JsonResponse
    {
        if ($kind === 'user') {
            $work->reviewers()->detach($id);
        } else {
            DB::table('reviewer_invite_work')->where('work_id', $work->id)->where('reviewer_invite_id', $id)->delete();
        }

        return $this->answer($work);
    }

    private function answer(Work $work): JsonResponse
    {
        return response()->json(['visibility' => $work->visibility->value, 'reviewers' => WorksAdmin::reviewersOn($work)]);
    }
}
