<?php

namespace App\Http\Controllers;

use App\Enums\CommentAudience;
use App\Models\Comment;
use App\Support\Preferences;
use App\Support\ProfilePage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Your Profile and its full lists. Private: only you see them.
 */
class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Profile', (new ProfilePage($request->user()))->toArray());
    }

    /**
     * Settings: your account, notifications, how chapters read, and deleting the account.
     */
    public function settings(Request $request): Response
    {
        $user = $request->user();
        $days = (int) config('eyiwetemi.username_hold_days');
        $unlocks = $user->username_changed_at?->copy()->addDays($days);

        return Inertia::render('Settings', [
            'account' => [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'seed' => $user->avatar_seed,
                'google' => $user->socialAccounts()->where('provider', 'google')->exists(),
                'hasPassword' => $user->password !== null,
                'pendingEmail' => $user->pending_email,
                'lockedUntil' => $unlocks?->isFuture() ? $unlocks->timezone(config('eyiwetemi.timezone'))->format('j M') : null,
                'comments' => Comment::query()->where('user_id', $user->id)->where('audience', CommentAudience::Public)->count(),
            ],
            'notifications' => Preferences::notificationsFor($user),
            'reading' => Preferences::readingFor($user),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function list(Request $request, string $which): Response
    {
        return Inertia::render('ProfileList', ['which' => $which, ...(new ProfilePage($request->user()))->list($which)]);
    }
}
