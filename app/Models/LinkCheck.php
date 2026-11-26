<?php

namespace App\Models;

use Database\Factories\LinkCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['url_hash', 'url', 'ok', 'status_code', 'checked_at'])]
class LinkCheck extends Model
{
    /** @use HasFactory<LinkCheckFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }
}
