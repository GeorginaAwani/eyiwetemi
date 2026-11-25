<?php

namespace App\Models;

use Database\Factories\CharacterLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['character_id', 'other_character_id', 'label'])]
class CharacterLink extends Model
{
    /** @use HasFactory<CharacterLinkFactory> */
    use HasFactory;

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'character_id');
    }

    /** @return BelongsTo<Character, $this> */
    public function otherCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'other_character_id');
    }
}
