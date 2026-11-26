<?php

namespace App\Support;

use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Her pictures (covers, pictures in essays and stories): opened with GD, made no bigger than they need to be,
 * stored as WebP on the media disk.
 */
class ImageStore
{
    /**
     * @param  string  $field  the form field, for the error message
     */
    public static function store(UploadedFile $file, string $folder, int $maxSide, User $by, string $field): Media
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw ValidationException::withMessages([$field => 'That picture couldn’t be opened. Try another.']);
        }

        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1, $maxSide / max($width, $height));

        if ($scale < 1) {
            $image = imagescale($image, (int) round($width * $scale), (int) round($height * $scale), IMG_BICUBIC) ?: $image;
        }

        ob_start();
        imagewebp($image, null, 84);
        $bytes = (string) ob_get_clean();

        $disk = (string) config('eyiwetemi.media_disk');
        $path = $folder.'/'.Str::uuid().'.webp';
        Storage::disk($disk)->put($path, $bytes, 'public');

        return Media::query()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime' => 'image/webp',
            'size' => strlen($bytes),
            'width' => imagesx($image),
            'height' => imagesy($image),
            'uploaded_by' => $by->id,
        ]);
    }
}
