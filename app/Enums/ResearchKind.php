<?php

namespace App\Enums;

enum ResearchKind: string
{
    case Link = 'link';
    case Image = 'image';
    case Note = 'note';
    case File = 'file';
}
