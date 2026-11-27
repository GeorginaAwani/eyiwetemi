<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reviewer's line note or suggested edit on a paragraph's text. The quote is checked against the draft in the
 * controller, since it needs the paragraph.
 */
class StoreReviewMarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The note is trimmed first, so a note of only spaces is empty.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'item' => ['required', 'integer'],
            'block' => ['required', 'string', 'max:64'],
            'start' => ['required', 'integer', 'min:0'],
            'end' => ['required', 'integer', 'gt:start'],
            'quote' => ['required', 'string', 'max:500'],
            'kind' => ['required', Rule::in(['line', 'edit'])],
            'body' => ['nullable', 'string', 'max:2000', 'required_if:kind,line'],
            'inserted' => ['nullable', 'string', 'max:500'],
        ];
    }
}
