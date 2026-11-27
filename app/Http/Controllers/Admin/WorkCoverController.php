<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Work;
use App\Support\ImageStore;
use App\Support\WorkPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

        $media = ImageStore::store($request->file('cover'), self::FOLDER, self::MAX_SIDE, $request->user(), 'cover');

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

        if (Work::query()->where('cover_media_id', $id)->exists()) {
            return;
        }

        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
    }
}
