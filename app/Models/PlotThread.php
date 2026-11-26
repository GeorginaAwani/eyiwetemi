<?php

namespace App\Models;

use Database\Factories\PlotThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['work_id', 'name', 'colour', 'position'])]
class PlotThread extends Model
{
    /** @use HasFactory<PlotThreadFactory> */
    use HasFactory;

    /** @return BelongsTo<Work, $this> */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    /** @return HasMany<PlotPoint, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(PlotPoint::class)->orderBy('position');
    }
}
