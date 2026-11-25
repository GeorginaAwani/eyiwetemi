<?php

namespace App\Enums;

enum WorkKind: string
{
    case Novel = 'novel';
    case Story = 'story';
    case Poem = 'poem';
    case Essay = 'essay';
    case Collection = 'collection';
}
