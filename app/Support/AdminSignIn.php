<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\Admin\NewSignIn;
use App\Notifications\Admin\SignInPaused;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticationProvider;

/**
 * The admin's sign-in, as approved: a first step (password, an emailed link or Google), then the authenticator
 * code unless this device is remembered (30 days). Five wrong tries pause sign-in for 15 minutes and email her.
 * A device that needed the code sends her a "new sign-in" email.
 */
class AdminSignIn
{
    public const DEVICE_COOKIE = 'eyi_admin_device';

    public const MAX_TRIES = 5;

    public const PAUSE_SECONDS = 900;

    /** How long the first step waits for the code. */
    public const PENDING_SECONDS = 600;

    public function __construct(private TwoFactorAuthenticationProvider $twoFactor) {}

    /** The author, when this email is hers. */
    public function author(string $email): ?User
    {
        return User::query()
            ->where('role', UserRole::Author)
            ->where('email', Str::lower(trim($email)))
            ->first();
    }

    /** Seconds left on the pause for this email (0 when sign-in is open). */
    public function pausedFor(string $email): int
    {
        $until = Cache::get($this->pauseKey($email));

        return $until ? max(0, (int) $until - now()->getTimestamp()) : 0;
    }

    /**
     * A wrong password, code or backup code. Returns the tries left; the fifth pauses sign-in and emails her.
     */
    public function failed(string $email): int
    {
        $key = $this->triesKey($email);
        $tries = (int) Cache::get($key, 0) + 1;

        if ($tries >= self::MAX_TRIES) {
            Cache::forget($key);
            Cache::put($this->pauseKey($email), now()->addSeconds(self::PAUSE_SECONDS)->getTimestamp(), self::PAUSE_SECONDS);
            $this->author($email)?->notify(new SignInPaused(now()->addSeconds(self::PAUSE_SECONDS)));

            return 0;
        }

        Cache::put($key, $tries, self::PAUSE_SECONDS);

        return self::MAX_TRIES - $tries;
    }

    public function clearTries(string $email): void
    {
        Cache::forget($this->triesKey($email));
    }

    /**
     * The first step passed: a remembered device signs straight in, anything else is asked for the code.
     *
     * @return 'in'|'code'
     */
    public function firstStepPassed(Request $request, User $user, string $via): string
    {
        $this->clearTries($user->email);

        if ($this->trustedDevice($request, $user)) {
            $this->complete($request, $user, newDevice: false);

            return 'in';
        }

        $request->session()->put('admin.pending', ['user_id' => $user->id, 'via' => $via, 'until' => now()->addSeconds(self::PENDING_SECONDS)->getTimestamp()]);

        return 'code';
    }

    /** The author waiting for her code, if the first step was passed in the last ten minutes. */
    public function pending(Request $request): ?User
    {
        $pending = $request->session()->get('admin.pending');

        if (! is_array($pending) || ($pending['until'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return User::query()->where('role', UserRole::Author)->find($pending['user_id']);
    }

    public function codeIsRight(User $user, string $code): bool
    {
        if (! $user->two_factor_secret || ! $user->two_factor_confirmed_at) {
            return false;
        }

        return $this->twoFactor->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code);
    }

    public function useBackupCode(User $user, string $code): bool
    {
        $code = trim($code);
        $match = collect($user->recoveryCodes())->first(fn (string $known): bool => hash_equals($known, $code));

        if (! $match) {
            return false;
        }

        $user->replaceRecoveryCode($match);

        return true;
    }

    /** Signed in: a fresh session marked as the admin's, the device remembered if asked, and a notice for a new one. */
    public function complete(Request $request, User $user, bool $newDevice, bool $remember = false): void
    {
        $request->session()->forget('admin.pending');
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('admin.verified', true);

        if ($remember) {
            $this->rememberDevice($request, $user);
        }

        if ($newDevice) {
            $user->notify(new NewSignIn(self::deviceLabel((string) $request->userAgent()), now()));
        }
    }

    public function trustedDevice(Request $request, User $user): ?TrustedDevice
    {
        $token = (string) $request->cookie(self::DEVICE_COOKIE);

        if ($token === '') {
            return null;
        }

        $device = TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        $device?->forceFill(['last_used_at' => now()])->save();

        return $device;
    }

    public function rememberDevice(Request $request, User $user): void
    {
        $token = Str::random(64);
        $days = (int) config('eyiwetemi.trusted_device_days');

        $user->trustedDevices()->create([
            'token_hash' => hash('sha256', $token),
            'label' => self::deviceLabel((string) $request->userAgent()),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($days),
        ]);

        Cookie::queue(Cookie::make(self::DEVICE_COOKIE, $token, $days * 24 * 60, '/', null, $request->isSecure(), true, false, 'lax'));
    }

    /** Forget this device: the next sign-in here asks for the code again. */
    public function forgetDevice(Request $request, User $user): void
    {
        $token = (string) $request->cookie(self::DEVICE_COOKIE);

        if ($token !== '') {
            $user->trustedDevices()->where('token_hash', hash('sha256', $token))->delete();
        }

        Cookie::queue(Cookie::forget(self::DEVICE_COOKIE));
    }

    /** "Edge on Windows", "Safari on iPhone"… for the new sign-in email and the devices list. */
    public static function deviceLabel(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Brave') => 'Brave',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'A browser',
        };

        $system = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $system ? "{$browser} on {$system}" : $browser;
    }

    private function triesKey(string $email): string
    {
        return 'admin-signin-tries|'.Str::lower(trim($email));
    }

    private function pauseKey(string $email): string
    {
        return 'admin-signin-pause|'.Str::lower(trim($email));
    }
}
