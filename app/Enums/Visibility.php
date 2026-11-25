<?php

namespace App\Enums;

enum Visibility: string
{
    case Private = 'private';
    case Reviewers = 'reviewers';
    case Public = 'public';
}
