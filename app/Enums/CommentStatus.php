<?php

namespace App\Enums;

enum CommentStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Resolved = 'resolved';
}
