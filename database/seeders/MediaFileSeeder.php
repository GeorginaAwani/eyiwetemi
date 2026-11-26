<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class MediaFileSeeder extends Seeder
{
    /**
     * Copy the design's stand-in pictures onto the media disk, keeping the paths the media rows point at.
     */
    public function run(): void
    {
        $source = database_path('seeders/media');
        $disk = Storage::disk(config('eyiwetemi.media_disk'));

        foreach (File::allFiles($source) as $file) {
            if ($file->getExtension() === 'txt') {
                continue;
            }

            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());

            if (! $disk->exists($path)) {
                $disk->put($path, File::get($file->getPathname()));
            }
        }
    }
}
