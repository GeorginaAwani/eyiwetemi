<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A new password ends every other way into the account: other signed-in sessions (database driver),
 * "remember me" cookies and remembered admin devices. The session making the change stays signed in.
 */
class OtherSessions
{
    public static function end(User $user, ?string $keepSessionId = null): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
                ->delete();
        }

        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $user->trustedDevices()->delete();
    }
}
