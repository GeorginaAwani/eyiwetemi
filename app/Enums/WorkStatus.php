<?php

namespace App\Enums;

enum WorkStatus: string
{
    case InProgress = 'in_progress';
    case Finished = 'finished';
    case ComingSoon = 'coming_soon';
}
