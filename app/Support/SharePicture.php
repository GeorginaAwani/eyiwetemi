<?php

namespace App\Support;

use App\Models\Media;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Her own share picture: cropped to the card's 1200 × 630 and kept as a JPEG on the media disk.
 */
class SharePicture
{
    /** Share pictures live under this folder on the media disk; only those are ever deleted. */
    public const FOLDER = 'share';

    public const WIDTH = 1200;

    public const HEIGHT = 630;

    private const JPEG_QUALITY = 85;

    /**
     * Crops a picture to fill the card, centred, and stores it.
     */
    public static function store(UploadedFile $file, User $by): Media
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw ValidationException::withMessages(['image' => 'That picture couldn’t be opened. Try another.']);
        }

        $bytes = self::jpeg(self::cropped($source));
        $disk = (string) config('eyiwetemi.media_disk');
        $path = self::FOLDER.'/'.Str::uuid().'.jpg';
        Storage::disk($disk)->put($path, $bytes, 'public');

        return Media::query()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime' => 'image/jpeg',
            'size' => strlen($bytes),
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'uploaded_by' => $by->id,
        ]);
    }

    /**
     * Deletes an uploaded share picture that no share entry uses any more.
     */
    public static function forget(?int $id): void
    {
        $media = $id ? Media::query()->find($id) : null;

        if (! $media || ! str_starts_with($media->path, self::FOLDER.'/') || SiteSharing::usesPicture($media->id)) {
            return;
        }

        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
    }

    private static function cropped(GdImage $source): GdImage
    {
        $region = ImageCrop::region(imagesx($source), imagesy($source), 0.5, 0.5, self::WIDTH, self::HEIGHT);
        $picture = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($picture, 0, 0, imagecolorallocate($picture, 255, 255, 255));
        imagecopyresampled($picture, $source, 0, 0, $region['x'], $region['y'], self::WIDTH, self::HEIGHT, $region['width'], $region['height']);

        return $picture;
    }

    private static function jpeg(GdImage $picture): string
    {
        ob_start();
        imagejpeg($picture, null, self::JPEG_QUALITY);

        return (string) ob_get_clean();
    }
}
