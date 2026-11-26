<?php

namespace App\Enums;

enum CommentAudience: string
{
    case Public = 'public';
    case Review = 'review';
}
