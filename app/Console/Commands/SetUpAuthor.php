<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use Laravel\Fortify\TwoFactorAuthenticationProvider;

/**
 * There is no sign-up: this sets up the one author account, its password and her authenticator app.
 * Recovery codes are shown once, so keep them somewhere safe.
 */
#[Signature('eyiwetemi:author {email} {--name=} {--password-only} {--new-codes}')]
#[Description('Set up the author account, its password and authenticator app')]
class SetUpAuthor extends Command
{
    private const RECOVERY_CODE_COUNT = 8;

    private const CODE_TRIES = 3;

    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * Nothing is saved until the password is checked, so a refused step leaves the account as it was.
     */
    public function handle(TwoFactorAuthenticationProvider $twoFactor): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('That email does not look right. Nothing changed.');

            return self::FAILURE;
        }

        $author = User::query()->where('role', UserRole::Author)->first();

        if ($this->option('new-codes')) {
            return $this->newRecoveryCodes($author);
        }

        $emailTaken = User::query()
            ->where('email', $email)
            ->when($author, fn ($query, User $current) => $query->whereKeyNot($current->getKey()))
            ->exists();

        if ($emailTaken) {
            $this->error('Another account already uses that email. Nothing changed.');

            return self::FAILURE;
        }

        if ($author !== null && $author->email !== $email && ! $this->confirm("Change the author's email from {$author->email} to {$email}?")) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        $name = null;

        if ($author === null) {
            $name = trim((string) ($this->option('name') ?: $this->ask('Her name')));

            if ($name === '') {
                $this->error('A name is needed. Nothing changed.');

                return self::FAILURE;
            }
        }

        $password = null;

        if ($author === null || blank($author->password) || $this->confirm('Change the password?', false)) {
            $password = $this->askForPassword();

            if ($password === null) {
                return self::FAILURE;
            }
        }

        if ($author === null) {
            $author = User::query()->forceCreate([
                'name' => $name,
                'email' => $email,
                'role' => UserRole::Author,
                'email_verified_at' => now(),
            ]);
        }

        $changes = [];

        if ($author->email !== $email) {
            $changes['email'] = $email;
            $changes['email_verified_at'] = now();
        }

        if ($password !== null) {
            $changes['password'] = $password;
        }

        if ($changes !== []) {
            $author->forceFill($changes)->save();
        }

        if ($password !== null) {
            $this->info('Password saved.');
        }

        if ($this->option('password-only')) {
            return self::SUCCESS;
        }

        if ($author->two_factor_confirmed_at !== null && ! $this->confirm('Replace the authenticator app? The current one stops working.', false)) {
            $this->line('Authenticator app kept.');

            return self::SUCCESS;
        }

        return $this->setUpAuthenticator($author, $twoFactor);
    }

    /**
     * The password twice; null when they differ or are too short.
     */
    private function askForPassword(): ?string
    {
        $password = (string) $this->secret('Password (at least 8 characters)');
        $again = (string) $this->secret('Type the password again');

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->error('The password needs at least 8 characters. Nothing changed.');

            return null;
        }

        if ($password !== $again) {
            $this->error('The passwords do not match. Nothing changed.');

            return null;
        }

        return $password;
    }

    /**
     * A new secret and backup codes, shown once. The app is on only after the first right code.
     */
    private function setUpAuthenticator(User $author, TwoFactorAuthenticationProvider $twoFactor): int
    {
        $secret = $twoFactor->generateSecretKey();
        $recoveryCodes = $this->makeRecoveryCodes();

        $author->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->newLine();
        $this->line('In your authenticator app, add an account with this key:');
        $this->line(implode(' ', str_split($secret, 4)));
        $this->newLine();
        $this->line('Or open this on the phone:');
        $this->line($author->twoFactorQrCodeUrl());
        $this->newLine();

        for ($try = 1; $try <= self::CODE_TRIES; $try++) {
            $code = preg_replace('/\D/', '', (string) $this->ask('6-digit code from the app'));

            if ($twoFactor->verify($secret, $code)) {
                $author->forceFill(['two_factor_confirmed_at' => now()])->save();

                $this->info('Authenticator app is on.');
                $this->showRecoveryCodes($recoveryCodes);

                return self::SUCCESS;
            }

            $this->error('That code is not right.');
        }

        $this->error('Not turned on. Run the command again to start over.');

        return self::FAILURE;
    }

    /**
     * New backup codes for a confirmed authenticator; the old ones stop working.
     */
    private function newRecoveryCodes(?User $author): int
    {
        if ($author === null || $author->two_factor_confirmed_at === null) {
            $this->error('Set up the authenticator app first, without --new-codes.');

            return self::FAILURE;
        }

        $recoveryCodes = $this->makeRecoveryCodes();

        $author->forceFill([
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($recoveryCodes)),
        ])->save();

        $this->info('New backup codes. The old ones no longer work.');
        $this->showRecoveryCodes($recoveryCodes);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function makeRecoveryCodes(): array
    {
        return Collection::times(self::RECOVERY_CODE_COUNT, fn (): string => RecoveryCode::generate())->all();
    }

    /**
     * @param  list<string>  $recoveryCodes
     */
    private function showRecoveryCodes(array $recoveryCodes): void
    {
        $this->newLine();
        $this->line('Backup codes. Each one works once, if you lose the app:');

        foreach ($recoveryCodes as $recoveryCode) {
            $this->line('  '.$recoveryCode);
        }

        $this->newLine();
        $this->warn('Keep these somewhere safe. They are shown only now.');
    }
}
