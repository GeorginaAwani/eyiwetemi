<?php

namespace App\Support;

/**
 * Where to take a picture from so it fills a box without stretching: the largest region of the same shape as the box, kept
 * centred on the focal point (a fraction of the picture's width and height) and inside the picture.
 */
class ImageCrop
{
    /**
     * The source rectangle to copy, in whole pixels, for a picture of the given size to fill a box of the given size.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public static function region(int $sourceWidth, int $sourceHeight, float $focusX, float $focusY, int $width, int $height): array
    {
        $scale = max($width / $sourceWidth, $height / $sourceHeight);
        $cropWidth = min($sourceWidth, $width / $scale);
        $cropHeight = min($sourceHeight, $height / $scale);

        return [
            'x' => (int) round(min(max(0, $focusX * $sourceWidth - $cropWidth / 2), $sourceWidth - $cropWidth)),
            'y' => (int) round(min(max(0, $focusY * $sourceHeight - $cropHeight / 2), $sourceHeight - $cropHeight)),
            'width' => (int) round($cropWidth),
            'height' => (int) round($cropHeight),
        ];
    }
}
