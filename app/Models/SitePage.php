<?php

namespace App\Models;

use App\Enums\SitePageKey;
use Database\Factories\SitePageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['key', 'draft', 'live', 'draft_saved_at', 'published_at'])]
class SitePage extends Model
{
    /** @use HasFactory<SitePageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => SitePageKey::class,
            'draft' => 'array',
            'live' => 'array',
            'draft_saved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return MorphMany<Version, $this>
     */
    public function versions(): MorphMany
    {
        return $this->morphMany(Version::class, 'versionable')->latest();
    }
}
