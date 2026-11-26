<?php

namespace App\Http\Requests\Admin;

use App\Enums\CollectionKind;
use App\Enums\WorkKind;
use App\Models\Work;
use App\Support\WorksAdmin;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * New work: a title no other work has, its kind, and (for a novel) a series or (for a single piece) a collection.
 */
class StoreWorkRequest extends FormRequest
{
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
            'title' => ['required', 'string', 'max:200', self::freeTitle()],
            'kind' => ['required', Rule::enum(WorkKind::class)],
            'collection_kind' => ['nullable', Rule::enum(CollectionKind::class)],
            'series_id' => ['nullable', 'integer', 'exists:series,id'],
            'collection_id' => ['nullable', 'integer', Rule::exists('works', 'id')->where('kind', WorkKind::Collection->value)],
            'new_collection' => ['nullable', 'string', 'max:200', self::freeTitle(), 'different:title'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Give it a title.',
            'new_collection.different' => 'You already have a work called :input.',
        ];
    }

    /**
     * No two works share a title (spaces and capitals aside).
     */
    public static function freeTitle(?Work $except = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($except): void {
            $taken = is_string($value) ? WorksAdmin::titleTaken($value, $except) : null;

            if ($taken) {
                $fail("You already have a work called {$taken->title}.");
            }
        };
    }
}
