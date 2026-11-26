<?php

namespace App\Http\Requests\Admin;

use App\Enums\CollectionKind;
use App\Enums\HeadingStyle;
use App\Enums\SceneBreak;
use App\Enums\Visibility;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Work;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A work's Settings: any one field (or a few) at a time, as she changes them.
 */
class UpdateWorkRequest extends FormRequest
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
        /** @var Work $work */
        $work = $this->route('work');

        return [
            'title' => ['sometimes', 'required', 'string', 'max:200', StoreWorkRequest::freeTitle($work)],
            'kind' => ['sometimes', Rule::enum(WorkKind::class)],
            'collection_kind' => ['sometimes', 'nullable', Rule::enum(CollectionKind::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::enum(WorkStatus::class)],
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'series_id' => ['sometimes', 'nullable', 'integer', 'exists:series,id'],
            'series_position' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'collection_id' => ['sometimes', 'nullable', 'integer', Rule::exists('works', 'id')->where('kind', WorkKind::Collection->value)],
            'new_collection' => ['sometimes', 'nullable', 'string', 'max:200', StoreWorkRequest::freeTitle()],
            'genres' => ['sometimes', 'array', 'max:2'],
            'genres.*' => ['string', 'max:60', 'distinct:ignore_case'],
            'heading_style' => ['sometimes', Rule::enum(HeadingStyle::class)],
            'show_chapter_titles' => ['sometimes', 'boolean'],
            'show_pov' => ['sometimes', 'boolean'],
            'part_word' => ['sometimes', 'nullable', 'string', 'max:40'],
            'part_style' => ['sometimes', Rule::enum(HeadingStyle::class)],
            'show_part_numbers' => ['sometimes', 'boolean'],
            'scene_break' => ['sometimes', Rule::enum(SceneBreak::class)],
            'content_notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Give it a title.',
            'genres.max' => 'Two genres at most.',
        ];
    }
}
