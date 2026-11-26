<?php

namespace App\Http\Controllers;

use App\Mail\ConfirmEmailChange;
use App\Models\Comment;
use App\Models\User;
use App\Models\UsernameHold;
use App\Rules\Username;
use App\Support\Preferences;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The reader's Settings page, answered as JSON: name, username, email, password, photo, notices and reading,
 * and deleting the account. Every change is for the signed-in reader, except the link in the email, which
 * is checked by its signature instead.
 */
class SettingsController extends Controller
{
    /**
     * Change the name shown on the account.
     */
    public function name(Request $request): JsonResponse
    {
        $request->merge(['name' => $request->string('name')->trim()->toString()]);
        $request->validate(['name' => ['required', 'string', 'max:80']], [
            'name.required' => 'Type your name.',
            'name.max' => 'Keep it under 80 characters.',
        ]);

        $user = $this->user($request);
        $user->forceFill(['name' => $request->string('name')->toString()])->save();

        return response()->json(['name' => $user->name]);
    }

    /**
     * Change the username. The old one is held for 30 days so nobody else can take it straight away,
     * and the next change waits 30 days after this one.
     */
    public function username(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $days = (int) config('eyiwetemi.username_hold_days');
        $username = $request->string('username')->trim()->lower()->toString();

        if ($user->username_changed_at !== null && $user->username_changed_at->copy()->addDays($days)->isFuture()) {
            $this->invalid('username', 'You can change it again from '.$this->shortDate($user->username_changed_at->copy()->addDays($days)));
        }

        if ($username === Str::lower($user->username)) {
            $this->invalid('username', 'That’s your username now.');
        }

        $request->merge(['username' => $username]);
        $request->validate(['username' => [new Username($user)]]);

        $oldUsername = $user->username;
        $holdUntil = now()->addDays($days);

        DB::transaction(function () use ($user, $username, $oldUsername, $holdUntil): void {
            UsernameHold::query()->updateOrCreate(
                ['username' => $oldUsername],
                ['user_id' => $user->id, 'released_at' => $holdUntil],
            );

            /* taking back a name you left: it's yours again, not held */
            UsernameHold::query()->where('username', $username)->where('user_id', $user->id)->delete();
            $user->forceFill(['username' => $username, 'username_changed_at' => now()])->save();
        });

        return response()->json([
            'username' => $username,
            'nextChange' => $this->shortDate(now()->addDays($days)),
        ]);
    }

    /**
     * Ask to change the email. Nothing changes until the new address opens the link in its email.
     */
    public function email(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $request->merge(['email' => $request->string('email')->trim()->lower()->toString()]);
        $request->validate([
            'email' => ['bail', 'required', 'email', 'max:255', Rule::notIn([$user->email]), Rule::unique(User::class)],
        ], [
            'email.required' => 'Type your new email.',
            'email.email' => 'That doesn’t look like an email.',
            'email.not_in' => 'That’s your email now.',
            'email.unique' => 'That email already has an account.',
        ]);

        if ($user->password === null) {
            $this->invalid('password', 'Add a password first.');
        }

        $request->validate(['password' => ['required', 'current_password']], [
            'password.required' => 'Type your password.',
            'password.current_password' => 'That password doesn’t match.',
        ]);

        $email = $request->string('email')->toString();
        $user->forceFill(['pending_email' => $email])->save();
        $this->sendEmailConfirmation($user, $email);

        return response()->json(['pending' => $email]);
    }

    /**
     * Send the confirmation to the waiting address again.
     */
    public function resendEmail(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if ($user->pending_email === null) {
            $this->invalid('email', 'There’s no change waiting.');
        }

        $this->sendEmailConfirmation($user, $user->pending_email);

        return response()->json(['pending' => $user->pending_email]);
    }

    /**
     * Drop the email change that is waiting.
     */
    public function cancelEmail(Request $request): JsonResponse
    {
        $this->user($request)->forceFill(['pending_email' => null])->save();

        return response()->json(['pending' => null]);
    }

    /**
     * The link in the email: the address becomes the account's email, if it is still the one waiting
     * and nobody else has it. Opened from an email, so it doesn't need a sign-in.
     */
    public function confirmEmail(Request $request, User $user): RedirectResponse
    {
        $email = Str::lower(trim((string) $request->query('email')));
        $taken = User::query()->where('email', $email)->whereKeyNot($user->id)->exists();

        if ($user->pending_email !== $email || $taken) {
            return redirect()->to('/settings')->with('status', 'That link has expired.');
        }

        $user->forceFill(['email' => $email, 'email_verified_at' => now(), 'pending_email' => null])->save();

        return redirect()->to('/settings')->with('status', 'Your email is changed.');
    }

    /**
     * Set a new password, or add one to a Google-only account (which needs no current password).
     * The reader stays signed in.
     */
    public function password(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $rules = ['password' => ['required', 'string', 'min:8', 'max:255']];
        $messages = [
            'password.required' => 'Type a new password.',
            'password.min' => 'At least 8 characters.',
        ];

        if ($user->password !== null) {
            $rules['current_password'] = ['required', 'current_password'];
            $messages['current_password.required'] = 'Type your current password.';
            $messages['current_password.current_password'] = 'That password doesn’t match.';
        }

        $request->validate($rules, $messages);

        $user->forceFill(['password' => $request->input('password')])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * A new random avatar: the seed the picture is drawn from.
     */
    public function photo(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $user->forceFill(['avatar_seed' => Str::random(16)])->save();

        return response()->json(['seed' => $user->avatar_seed]);
    }

    /**
     * Switch one notice on or off, for the site or for email.
     */
    public function notifications(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys(Preferences::NOTIFICATION_DEFAULTS))],
            'on' => ['required', 'boolean'],
        ]);

        $user = $this->user($request);
        $preferences = $user->preferences ?? [];
        $preferences['notifications'][$data['key']] = $request->boolean('on');
        $user->forceFill(['preferences' => $preferences])->save();

        return response()->json(['notifications' => Preferences::notificationsFor($user)]);
    }

    /**
     * How a chapter reads: only the settings sent are changed, the rest stay as they were.
     */
    public function reading(Request $request): JsonResponse
    {
        $data = $request->validate([
            'size' => ['sometimes', 'integer', 'between:1,4'],
            'font' => ['sometimes', 'in:serif,sans'],
            'width' => ['sometimes', 'in:narrow,wide'],
            'lines' => ['sometimes', 'in:close,airy'],
            'theme' => ['sometimes', 'in:white,paper,grey,night'],
        ]);

        if (isset($data['size'])) {
            $data['size'] = (int) $data['size'];
        }

        $user = $this->user($request);
        $preferences = $user->preferences ?? [];
        $preferences['reading'] = array_merge($preferences['reading'] ?? [], $data);
        $user->forceFill(['preferences' => $preferences])->save();

        return response()->json(['reading' => Preferences::readingFor($user)]);
    }

    /**
     * Delete the account. Their own comments are blanked and removed, so replies from others stay under
     * "Comment deleted". Then they are signed out.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if ($request->string('username')->trim()->lower()->toString() !== Str::lower($user->username)) {
            $this->invalid('username', 'Type your username to confirm.');
        }

        DB::transaction(function () use ($user): void {
            $user->comments()->withTrashed()->get()->each(function (Comment $comment): void {
                $comment->forceFill(['body' => null])->save();

                if (! $comment->trashed()) {
                    $comment->delete();
                }
            });

            // Signed out before the row goes: logging out after the delete would write the remember token back.
            Auth::guard('web')->logout();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['redirect' => '/']);
    }

    /**
     * The confirmation link works for a day and only for the address it was sent to.
     */
    private function sendEmailConfirmation(User $user, string $email): void
    {
        $confirmUrl = URL::temporarySignedRoute('settings.email.confirm', now()->addDay(), [
            'user' => $user->getKey(),
            'email' => $email,
        ]);

        Mail::to($email)->queue(new ConfirmEmailChange($confirmUrl));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * A date the way the site writes it, in West Africa Time: "23 Dec".
     */
    private function shortDate(CarbonInterface $at): string
    {
        return $at->timezone(config('eyiwetemi.timezone'))->format('j M');
    }

    /**
     * @throws ValidationException
     */
    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
