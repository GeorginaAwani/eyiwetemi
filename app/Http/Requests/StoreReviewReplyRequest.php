<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A reviewer's reply to one of their own marks.
 */
class StoreReviewReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The reply is trimmed first, so a reply of only spaces is empty.
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
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
