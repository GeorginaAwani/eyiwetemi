<?php

namespace App\Enums;

enum VersionLabel: string
{
    case Published = 'published';
    case Updated = 'updated';
    case Unpublished = 'unpublished';
    case DraftBeforeRestore = 'draft_before_restore';
    case DraftFromDevice = 'draft_from_device';
}
