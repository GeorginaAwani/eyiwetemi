<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\LoginLink;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\Admin\ResetPassword;
use App\Notifications\Admin\SignInLink;
use App\Support\AdminSignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * The admin sign-in page and each of its steps. The steps answer in JSON; the page moves between them itself.
 * Emails that aren't hers get the same answers as hers, so the page never says whose admin this is.
 */
class SignInController extends Controller
{
    use PasswordValidationRules;

    public function __construct(private AdminSignIn $signIn) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get('admin.verified') && $request->user()?->isAuthor()) {
            return redirect()->route('admin.desk');
        }

        $pending = $this->signIn->pending($request);
        $reset = $request->session()->get('admin.reset');

        return Inertia::render('Admin/SignIn', [
            'step' => match (true) {
                is_array($reset) => 'reset',
                $pending !== null => 'code',
                default => 'email',
            },
            'email' => $pending?->email ?? ($reset['email'] ?? null),
            'pausedFor' => $pending ? $this->signIn->pausedFor($pending->email) : 0,
            'error' => $request->session()->get('admin.error'),
        ]);
    }

    /** Email: only checks whether sign-in is paused for it. */
    public function email(Request $request): JsonResponse
    {
        $email = $this->validEmail($request);

        return response()->json(['pausedFor' => $this->signIn->pausedFor($email)]);
    }

    public function password(Request $request): JsonResponse
    {
        $email = $this->validEmail($request);
        $request->validate(['password' => ['required', 'string']], ['password.required' => 'Type your password first.']);

        if ($paused = $this->signIn->pausedFor($email)) {
            return response()->json(['pausedFor' => $paused], 423);
        }

        $user = $this->signIn->author($email);

        if (! $user || ! $user->password || ! Hash::check($request->string('password')->toString(), $user->password)) {
            return $this->wrong($email, 'password', 'That password isn’t right.');
        }

        return $this->passed($request, $user, 'password');
    }

    public function code(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $this->signIn->pending($request);

        if (! $user) {
            return response()->json(['expired' => true], 409);
        }
        if ($paused = $this->signIn->pausedFor($user->email)) {
            return response()->json(['pausedFor' => $paused], 423);
        }
        if (! $this->signIn->codeIsRight($user, preg_replace('/\D/', '', $request->string('code')->toString()))) {
            return $this->wrong($user->email, 'code', 'That code isn’t right. Try the newest one.', countdown: false);
        }

        $this->signIn->complete($request, $user, newDevice: true, remember: $request->boolean('remember'));

        return response()->json(['redirect' => $this->intended($request)]);
    }

    public function backup(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']], ['code.required' => 'Type a backup code first.']);
        $user = $this->signIn->pending($request);

        if (! $user) {
            return response()->json(['expired' => true], 409);
        }
        if ($paused = $this->signIn->pausedFor($user->email)) {
            return response()->json(['pausedFor' => $paused], 423);
        }
        if (! $this->signIn->useBackupCode($user, $request->string('code')->toString())) {
            return $this->wrong($user->email, 'code', 'That backup code isn’t right.', countdown: false);
        }

        $this->signIn->complete($request, $user, newDevice: true);

        return response()->json(['redirect' => $this->intended($request)]);
    }

    /** Email me a sign-in link: works for 15 minutes, once. */
    public function sendLink(Request $request): JsonResponse
    {
        $email = $this->validEmail($request);

        if ($user = $this->signIn->author($email)) {
            $token = Str::random(64);
            $user->loginLinks()->create([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes((int) config('eyiwetemi.login_link_minutes')),
            ]);
            $user->notify(new SignInLink(route('admin.link.open', $token)));
        }

        return response()->json(['sent' => true]);
    }

    public function openLink(Request $request, string $token): RedirectResponse
    {
        $link = LoginLink::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $link || ! $link->user->isAuthor()) {
            return redirect()->route('admin.signin')->with('admin.error', 'That link doesn’t work any more.');
        }

        $link->forceFill(['used_at' => now()])->save();

        return $this->signIn->firstStepPassed($request, $link->user, 'link') === 'in'
            ? redirect()->to($this->intended($request))
            : redirect()->route('admin.signin');
    }

    /** Forgot it?: a reset link to her inbox (the same answer for any email). */
    public function forgot(Request $request): JsonResponse
    {
        $email = $this->validEmail($request);

        if ($user = $this->signIn->author($email)) {
            $user->notify(new ResetPassword(Password::broker()->createToken($user), $user->email));
        }

        return response()->json(['sent' => true]);
    }

    /** The reset email's link: the token leaves the address bar and rides in the session to the New password step. */
    public function openReset(Request $request, string $token): RedirectResponse
    {
        $request->session()->put('admin.reset', ['token' => $token, 'email' => $request->string('email')->toString()]);

        return redirect()->route('admin.signin');
    }

    public function reset(Request $request): JsonResponse
    {
        $reset = $request->session()->get('admin.reset');
        abort_unless(is_array($reset), 409);

        $request->validate(['password' => $this->passwordRules()], $this->passwordMessages());

        $user = null;
        $status = Password::broker()->reset(
            ['email' => $reset['email'], 'token' => $reset['token'], 'password' => $request->string('password')->toString()],
            function (User $resetting, string $password) use (&$user): void {
                $resetting->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user = $resetting;
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $user?->isAuthor()) {
            return response()->json(['errors' => ['password' => ['That link doesn’t work any more.']]], 422);
        }

        $request->session()->forget('admin.reset');

        return $this->passed($request, $user, 'reset');
    }

    public function cancelReset(Request $request): JsonResponse
    {
        $request->session()->forget('admin.reset');

        return response()->json(['ok' => true]);
    }

    public function google(): SymfonyRedirect|RedirectResponse
    {
        if (blank(config('services.google.client_id'))) {
            return redirect()->route('admin.signin')->with('admin.error', 'Google sign-in isn’t set up yet.');
        }

        return Socialite::driver('google')->redirectUrl(route('admin.google.callback'))->redirect();
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->redirectUrl(route('admin.google.callback'))->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('admin.signin')->with('admin.error', 'Google didn’t sign you in. Try again.');
        }

        $user = SocialAccount::query()->with('user')->where('provider', 'google')->where('provider_user_id', $google->getId())->first()?->user;

        if (! $user && ($google->user['email_verified'] ?? false)) {
            $user = $this->signIn->author((string) $google->getEmail());
            $user?->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => $google->getId()]);
        }

        if (! $user?->isAuthor()) {
            return redirect()->route('admin.signin')->with('admin.error', 'That Google account can’t sign in here.');
        }

        return $this->signIn->firstStepPassed($request, $user, 'google') === 'in'
            ? redirect()->to($this->intended($request))
            : redirect()->route('admin.signin');
    }

    public function signOut(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.signin');
    }

    public function forgetDevice(Request $request): JsonResponse
    {
        $this->signIn->forgetDevice($request, $request->user());

        return response()->json(['ok' => true]);
    }

    private function validEmail(Request $request): string
    {
        $request->validate(
            ['email' => ['required', 'string', 'email', 'max:255']],
            ['email.required' => 'Type your email first.', 'email.email' => 'That doesn’t look like an email.'],
        );

        return Str::lower(trim($request->string('email')->toString()));
    }

    private function passed(Request $request, User $user, string $via): JsonResponse
    {
        return $this->signIn->firstStepPassed($request, $user, $via) === 'in'
            ? response()->json(['redirect' => $this->intended($request)])
            : response()->json(['next' => 'code']);
    }

    /** A wrong answer: the line under the field, how many tries are left once three or fewer, or the pause. */
    private function wrong(string $email, string $field, string $message, bool $countdown = true): JsonResponse
    {
        $left = $this->signIn->failed($email);

        if ($left === 0) {
            return response()->json(['pausedFor' => $this->signIn->pausedFor($email)], 423);
        }
        if ($countdown && $left <= 3) {
            $message .= ' '.$left.' '.($left === 1 ? 'try' : 'tries').' left.';
        }

        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    /** Back to the admin page she asked for, never anywhere off the admin host. */
    private function intended(Request $request): string
    {
        $intended = (string) $request->session()->pull('url.intended');

        return parse_url($intended, PHP_URL_HOST) === config('eyiwetemi.admin_domain') ? $intended : route('admin.desk');
    }
}
