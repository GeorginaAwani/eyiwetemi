<?php

namespace App\Models;

use Database\Factories\SlugHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['sluggable_type', 'sluggable_id', 'slug'])]
class SlugHistory extends Model
{
    /** @use HasFactory<SlugHistoryFactory> */
    use HasFactory;

    protected $table = 'slug_history';

    /** @return MorphTo<Model, $this> */
    public function sluggable(): MorphTo
    {
        return $this->morphTo();
    }
}
