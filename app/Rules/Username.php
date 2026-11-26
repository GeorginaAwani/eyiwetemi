<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Usernames;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A username someone can take: the right characters and length, not kept for the author, not taken or held.
 */
class Username implements ValidationRule
{
    public function __construct(private ?User $except = null) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Pick a username.');

            return;
        }

        $verdict = Usernames::check($value, '', $this->except);

        if (! $verdict['ok']) {
            $fail($verdict['message']);
        }
    }
}
