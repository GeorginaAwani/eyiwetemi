<?php

namespace App\Enums;

enum SendStatus: string
{
    case Planned = 'planned';
    case Held = 'held';
    case Skipped = 'skipped';
    case Sending = 'sending';
    case Sent = 'sent';
}
