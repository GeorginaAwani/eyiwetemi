<?php

namespace App\Models;

use Database\Factories\SeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['name', 'name_key', 'slug', 'description'])]
class Series extends Model
{
    /** @use HasFactory<SeriesFactory> */
    use HasFactory;

    protected $table = 'series';

    /** @return HasMany<Work, $this> */
    public function works(): HasMany
    {
        return $this->hasMany(Work::class)->orderBy('series_position');
    }

    /** @return MorphMany<SlugHistory, $this> */
    public function slugHistory(): MorphMany
    {
        return $this->morphMany(SlugHistory::class, 'sluggable');
    }

    /** @return MorphMany<Follow, $this> */
    public function follows(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }
}
