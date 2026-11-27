<?php

namespace App\Models;

use App\Enums\VersionLabel;
use Database\Factories\VersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['versionable_type', 'versionable_id', 'label', 'body', 'words', 'words_added', 'words_cut', 'created_by', 'device'])]
class Version extends Model
{
    /** @use HasFactory<VersionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'label' => VersionLabel::class,
            'body' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function versionable(): MorphTo
    {
        return $this->morphTo();
    }
}
