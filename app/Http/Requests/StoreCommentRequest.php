<?php

namespace App\Http\Requests;

use App\Enums\CommentAudience;
use App\Models\Comment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCommentRequest extends FormRequest
{
    /** Longest comment a reader can post, in characters. */
    public const MAX_LENGTH = 2000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The body is trimmed first, so a body of only spaces is empty.
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
            'work_item_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'min:1', 'max:'.self::MAX_LENGTH],
            'reply_to' => ['nullable', 'integer'],
        ];
    }

    /**
     * A reply must answer a visible comment on the same chapter or piece.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('reply_to') !== null && $this->replyTarget() === null) {
                $validator->errors()->add('reply_to', 'That comment is no longer open for replies.');
            }
        });
    }

    /**
     * The comment being replied to, when it is one a reader can see on this chapter or piece.
     */
    public function replyTarget(): ?Comment
    {
        if ($this->input('reply_to') === null) {
            return null;
        }

        return once(fn (): ?Comment => Comment::query()
            ->where('audience', CommentAudience::Public)
            ->whereNull('hidden_at')
            ->where('work_item_id', $this->integer('work_item_id'))
            ->whereKey($this->integer('reply_to'))
            ->first());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'work_item_id.required' => 'Pick the chapter or piece to comment on.',
            'body.required' => 'Type a comment first.',
            'body.max' => 'Keep comments to '.self::MAX_LENGTH.' characters.',
        ];
    }
}
