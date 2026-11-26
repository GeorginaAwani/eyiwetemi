<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\SignedInResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Sign in and Create account answer the modal with where to go next (or nowhere: stay and carry on).
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponse::class, SignedInResponse::class);
        $this->app->singleton(RegisterResponse::class, SignedInResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        /* each failure says what's wrong: no such account, a Google-only account, or the wrong password */
        Fortify::authenticateUsing(function (Request $request): User {
            $user = User::query()->where('email', Str::lower(trim((string) $request->input('email'))))->first();

            if (! $user) {
                throw ValidationException::withMessages(['email' => 'No account uses this email.']);
            }
            if ($user->password === null) {
                throw ValidationException::withMessages(['password' => 'This account uses Google.']);
            }
            if (! Hash::check((string) $request->input('password'), $user->password)) {
                app(LoginRateLimiter::class)->increment($request);

                throw ValidationException::withMessages(['password' => trans('auth.failed')]);
            }

            return $user;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
