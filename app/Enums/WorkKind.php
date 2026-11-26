<?php

namespace App\Enums;

enum WorkKind: string
{
    case Novel = 'novel';
    case Story = 'story';
    case Poem = 'poem';
    case Essay = 'essay';
    case Collection = 'collection';

    /**
     * A poem, story or essay: one piece, which may sit in a collection.
     */
    public function isSingle(): bool
    {
        return in_array($this, [self::Story, self::Poem, self::Essay], true);
    }
}
