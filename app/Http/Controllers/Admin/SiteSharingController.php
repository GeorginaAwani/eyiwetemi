<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Work;
use App\Support\SharePicture;
use App\Support\SiteSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Share cards: the line a link preview says, whether her own picture is used, and the picture itself. Each is for the whole
 * site (no slug) or for one work readers can see.
 */
class SiteSharingController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['present', 'nullable', 'string', 'max:255'],
            'own' => ['sometimes', 'boolean'],
            'line' => ['sometimes', 'nullable', 'string', 'max:160'],
        ]);

        $slug = $this->shareable($data['slug']);
        $changes = [];

        if (array_key_exists('own', $data)) {
            if ($data['own'] && SiteSharing::imageId($slug) === null) {
                throw ValidationException::withMessages(['own' => 'Add a picture first.']);
            }

            $changes['own'] = (bool) $data['own'];
        }

        if (array_key_exists('line', $data)) {
            $changes['line'] = (string) ($data['line'] ?? '');
        }

        SiteSharing::change($slug, $changes);

        return response()->json(['sharing' => SiteSharing::props()]);
    }

    /**
     * Her picture for the site or a work: cropped to 1200 × 630, and used from now on.
     */
    public function picture(Request $request): JsonResponse
    {
        $request->validate(
            [
                'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
                'slug' => ['nullable', 'string', 'max:255'],
            ],
            ['image.mimes' => 'That isn’t a picture we can use. Try a JPG, PNG or WebP.', 'image.max' => 'That picture is too big. Try one under 10 MB.'],
        );

        $slug = $this->shareable($request->input('slug'));
        $previous = SiteSharing::imageId($slug);
        $media = SharePicture::store($request->file('image'), $request->user());

        SiteSharing::change($slug, ['own' => true, 'image' => $media->id]);
        SharePicture::forget($previous);

        return response()->json(['sharing' => SiteSharing::props()]);
    }

    /**
     * The work a share card is for, when a slug is given: a work readers can see. Null is the whole site.
     */
    private function shareable(?string $slug): ?string
    {
        if ($slug !== null && ! Work::query()->listed()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'Only a work readers can see has its own share card.']);
        }

        return $slug;
    }
}
