<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Work;
use App\Support\WorkPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A work's cover: her picture (kept at most 1800 px tall, as WebP), or back to the coloured front with the title.
 */
class WorkCoverController extends Controller
{
    /** Covers she uploads here live under this folder; only those are ever deleted. */
    public const FOLDER = 'covers';

    private const MAX_SIDE = 1800;

    public function store(Request $request, Work $work): JsonResponse
    {
        $request->validate(
            ['cover' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:15360']],
            ['cover.mimes' => 'That isn’t a picture we can use. Try a JPG, PNG or WebP.', 'cover.max' => 'That picture is too big. Try one under 15 MB.'],
        );

        $image = @imagecreatefromstring((string) file_get_contents($request->file('cover')->getRealPath()));

        if ($image === false) {
            throw ValidationException::withMessages(['cover' => 'That picture couldn’t be opened. Try another.']);
        }

        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1, self::MAX_SIDE / max($width, $height));

        if ($scale < 1) {
            $image = imagescale($image, (int) round($width * $scale), (int) round($height * $scale), IMG_BICUBIC) ?: $image;
        }

        ob_start();
        imagewebp($image, null, 84);
        $bytes = (string) ob_get_clean();

        $disk = (string) config('eyiwetemi.media_disk');
        $path = self::FOLDER.'/'.Str::uuid().'.webp';
        Storage::disk($disk)->put($path, $bytes, 'public');

        $media = Media::query()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($request->file('cover')->getClientOriginalName(), 200, ''),
            'mime' => 'image/webp',
            'size' => strlen($bytes),
            'width' => imagesx($image),
            'height' => imagesy($image),
            'uploaded_by' => $request->user()->id,
        ]);

        $old = $work->cover_media_id;
        $work->forceFill(['cover_media_id' => $media->id])->save();
        self::forget($old);

        return response()->json(['cover' => WorkPresenter::cover($work->load('cover'))]);
    }

    public function destroy(Work $work): JsonResponse
    {
        $old = $work->cover_media_id;
        $work->forceFill(['cover_media_id' => null, 'cover_color' => $work->cover_color ?: '#DD6F94'])->save();
        self::forget($old);

        return response()->json(['cover' => WorkPresenter::cover($work->load('cover'))]);
    }

    /**
     * Delete an uploaded cover nothing uses any more.
     */
    public static function forget(?int $id): void
    {
        $media = $id ? Media::query()->find($id) : null;

        if (! $media || ! str_starts_with($media->path, self::FOLDER.'/')) {
            return;
        }

        if (Work::query()->where('cover_media_id', $id)->orWhere('share_media_id', $id)->exists()) {
            return;
        }

        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
    }
}
