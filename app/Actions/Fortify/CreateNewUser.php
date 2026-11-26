<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\Username;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Create account: name, username, email and password. Emails are one account each.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $input['email'] = Str::lower(trim((string) ($input['email'] ?? '')));
        $input['username'] = Str::lower(trim((string) ($input['username'] ?? '')));

        Validator::make($input, [
            'name' => ['required', 'string', 'max:80'],
            'username' => [new Username],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ], [
            'name.required' => 'Type your name.',
            'name.max' => 'Keep it under 80 characters.',
            'email.required' => 'Type your email.',
            'email.email' => 'That doesn’t look like an email.',
            'email.unique' => 'There’s already an account with this email.',
            ...$this->passwordMessages(),
        ])->validate();

        return User::create([
            'name' => trim((string) $input['name']),
            'username' => $input['username'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
