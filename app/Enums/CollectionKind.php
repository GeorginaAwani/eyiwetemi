<?php

namespace App\Enums;

enum CollectionKind: string
{
    case Story = 'story';
    case Poem = 'poem';
    case Essay = 'essay';
}
