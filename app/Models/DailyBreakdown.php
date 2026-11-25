<?php

namespace App\Models;

use App\Enums\StatDimension;
use Database\Factories\DailyBreakdownFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['day', 'dimension', 'value', 'reads'])]
class DailyBreakdown extends Model
{
    /** @use HasFactory<DailyBreakdownFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'dimension' => StatDimension::class,
        ];
    }
}
