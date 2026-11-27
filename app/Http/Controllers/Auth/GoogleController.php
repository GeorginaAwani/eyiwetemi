<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Rules\Username;
use App\Support\OtherSessions;
use App\Support\Usernames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Continue with Google: a known Google account (or a verified email we already have) signs straight in;
 * a new one comes back to "One more thing", where only the username is left to pick.
 */
class GoogleController extends Controller
{
    public function redirect(Request $request): SymfonyRedirect|RedirectResponse
    {
        $request->session()->put('google.back', $this->safePath($request->string('back')->toString()));

        if (blank(config('services.google.client_id'))) {
            return $this->back($request, ['view' => 'main', 'error' => 'Google sign-in isn’t set up yet.']);
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            /* the reader sees a short line; the log keeps why (wrong keys, an old state, a cancelled picker) */
            report($e);

            return $this->back($request, ['view' => 'main', 'error' => 'Google didn’t sign you in. Try again.']);
        }

        $account = SocialAccount::query()->with('user')->where('provider', 'google')->where('provider_user_id', $google->getId())->first();
        $user = $account?->user;
        $email = Str::lower((string) $google->getEmail());

        if (! $user && $email !== '' && ($google->user['email_verified'] ?? false)) {
            $user = User::query()->where('email', $email)->first();
            $user?->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => $google->getId()]);

            /* Google has now proved this address. A password set on it before anyone proved it may not be the owner's
               (someone could have signed up with her address first), so it stops working, with any session it opened. */
            if ($user && $user->email_verified_at === null && ! $user->isAuthor()) {
                if ($user->password !== null) {
                    $user->forceFill(['password' => null])->save();
                    OtherSessions::end($user);
                }
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        }

        if ($user) {
            Auth::login($user, true);
            $request->session()->regenerate();

            return $this->back($request);
        }

        $name = trim((string) ($google->getName() ?: Str::before($email, '@')));
        $request->session()->put('google.pending', ['id' => (string) $google->getId(), 'name' => $name, 'email' => $email, 'verified' => (bool) ($google->user['email_verified'] ?? false)]);

        return $this->back($request, ['view' => 'google', 'google' => ['name' => $name, 'email' => $email, 'username' => Usernames::offerFor($name)]]);
    }

    /**
     * "One more thing": the username for a new Google account.
     */
    public function finish(Request $request): JsonResponse
    {
        $pending = $request->session()->get('google.pending');
        abort_unless(is_array($pending), 419);

        $request->merge(['username' => Str::lower(trim($request->string('username')->toString()))]);
        $request->validate(['username' => [new Username]]);

        $user = DB::transaction(function () use ($pending, $request): User {
            $user = User::create([
                'name' => $pending['name'],
                'username' => $request->string('username')->toString(),
                'email' => $pending['email'],
            ]);
            /* only an address Google says is proved counts as proved here */
            if ($pending['verified'] ?? false) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => $pending['id']]);

            return $user;
        });

        $request->session()->forget('google.pending');
        Auth::login($user, true);
        $request->session()->regenerate();

        return response()->json(['redirect' => $request->session()->pull('url.intended') ?? $request->session()->pull('google.back')]);
    }

    /**
     * Back to the page they were on (or the private page they were heading for), with the card if it's needed.
     *
     * @param  array<string, mixed>|null  $signin
     */
    private function back(Request $request, ?array $signin = null): RedirectResponse
    {
        $to = $request->session()->get('google.back') ?? '/';

        if ($signin === null) {
            $request->session()->forget('google.back');
            $to = $request->session()->pull('url.intended') ?? $to;
        }

        return redirect()->to($to)->with('signin', $signin);
    }

    /**
     * Only a path on this site: "/library/ember" yes, "//elsewhere.com" or "https://…" no.
     */
    private function safePath(string $path): string
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\') ? $path : '/';
    }
}
