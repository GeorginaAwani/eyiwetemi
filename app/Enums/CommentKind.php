<?php

namespace App\Enums;

enum CommentKind: string
{
    case Comment = 'comment';
    case Line = 'line';
    case Edit = 'edit';
    case Book = 'book';
}
