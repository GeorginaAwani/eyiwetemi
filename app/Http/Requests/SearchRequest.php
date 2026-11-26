<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SearchRequest extends FormRequest
{
    /** Longest search, in characters, after the spaces at its ends are trimmed. */
    public const MAX_LENGTH = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The search is trimmed before it is checked, so spaces at its ends don't count toward the limit.
     */
    protected function prepareForValidation(): void
    {
        $query = $this->query('q');

        $this->merge(['q' => is_string($query) ? trim($query) : $query]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    /**
     * A too-long search is a JSON 422 for every caller, not a redirect back to the page.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['errors' => $validator->errors()], 422));
    }
}
