<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscribeRequest extends FormRequest
{
    /** The kinds of new work a reader can ask for by email. */
    public const KINDS = ['novels', 'stories', 'poems', 'essays', 'notes'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'kinds' => ['required', 'array', 'min:1'],
            'kinds.*' => ['string', Rule::in(self::KINDS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Type your email.',
            'email.email' => "That isn't an email address.",
            'kinds.required' => 'Pick at least one.',
            'kinds.min' => 'Pick at least one.',
        ];
    }
}
