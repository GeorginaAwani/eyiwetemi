<?php

namespace App\Actions\Fortify;

trait PasswordValidationRules
{
    /**
     * Passwords: 8 or more characters (no second "confirm" field in the design).
     *
     * @return list<string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', 'min:8', 'max:255'];
    }

    /**
     * @return array<string, string>
     */
    protected function passwordMessages(): array
    {
        return [
            'password.required' => 'Type a password.',
            'password.min' => '8 or more characters.',
        ];
    }
}
