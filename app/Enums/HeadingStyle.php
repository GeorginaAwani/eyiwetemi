<?php

namespace App\Enums;

enum HeadingStyle: string
{
    case ChapterWord = 'chapter_word';
    case ChapterWordLower = 'chapter_word_lower';
    case ChapterNumber = 'chapter_number';
    case ChapterRoman = 'chapter_roman';
    case Word = 'word';
    case Number = 'number';
}
