<?php

namespace App\Models;

use App\Enums\StatSubject;
use Database\Factories\DailyStatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['day', 'subject', 'subject_id', 'work_id', 'reads', 'readers', 'finished'])]
class DailyStat extends Model
{
    /** @use HasFactory<DailyStatFactory> */
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
            'subject' => StatSubject::class,
        ];
    }
}
