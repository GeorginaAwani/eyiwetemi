<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CountRequest extends FormRequest
{
    /** The site pages a visit can be counted on. */
    public const PAGES = ['home', 'about', 'library', 'in_print', 'notes', 'privacy', 'terms', 'work', 'series', 'other'];

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
            't' => ['required', Rule::in(['item', 'note', 'page'])],
            'e' => ['required', Rule::in(['read', 'end'])],
            'id' => ['required_unless:t,page', 'integer', 'min:1'],
            'page' => ['required_if:t,page', Rule::in(self::PAGES)],
            'from' => ['nullable', 'string', 'max:64'],
            'ref' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * A bad hit is answered like a good one: the reader is never told what was or wasn't counted.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->noContent());
    }
}
