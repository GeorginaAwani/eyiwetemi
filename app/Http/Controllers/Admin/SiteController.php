<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SitePageKey;
use App\Http\Controllers\Controller;
use App\Models\SitePage;
use App\Support\ImageStore;
use App\Support\SafeFetch;
use App\Support\SitePageDraft;
use App\Support\SitePages;
use App\Support\SitePublisher;
use App\Support\WorkPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The site's own pages (About, In print, Privacy, Terms): her drafts as she saves them, publishing and taking a publish back,
 * restoring a version, her portrait, and the check of a store link. Each answer carries the page's new state.
 */
class SiteController extends Controller
{
    /** Portraits live under this folder on the media disk. */
    private const PORTRAIT_FOLDER = 'portraits';

    /** Portraits are kept no bigger than the square they are cropped to. */
    private const PORTRAIT_SIDE = 600;

    public function index(): InertiaResponse
    {
        return Inertia::render('Admin/Site', SitePages::props());
    }

    /**
     * Her draft of a page, checked and kept in the page's shape. Saving never changes what is live.
     */
    public function update(Request $request, SitePageKey $key): JsonResponse
    {
        if (is_array($request->input('draft'))) {
            $request->merge(['draft' => SitePageDraft::prepare($key, $request->input('draft'))]);
        }

        $data = $request->validate(SitePageDraft::rules($key));

        $savedAt = now();
        $record = SitePage::query()->firstOrCreate(['key' => $key], ['draft' => SitePageDraft::empty($key)]);
        $record->forceFill(['draft' => SitePageDraft::normalise($key, $data['draft']), 'draft_saved_at' => $savedAt])->save();

        return response()->json(['savedAt' => SitePages::wat($savedAt)]);
    }

    /**
     * Sends the draft out: it becomes what is live, with a version of it. Privacy and Terms can say that the change matters.
     */
    public function publish(Request $request, SitePageKey $key): JsonResponse
    {
        $data = $request->validate([
            'matters' => ['sometimes', 'boolean'],
            'line' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $published = SitePublisher::publish($key, $request->user(), $request->boolean('matters'), $data['line'] ?? null);

        if ($published === null) {
            return response()->json(['message' => 'Nothing has changed since it was published.'], 409);
        }

        return response()->json(['page' => SitePages::pageOf($key), 'noteId' => $published['noteId']]);
    }

    /**
     * Takes back a publish made in the last two minutes: what was live comes back, and the version, note and notices it made go.
     */
    public function undo(SitePageKey $key): JsonResponse
    {
        $undo = Cache::get(SitePublisher::undoKey($key));

        if ($undo === null) {
            return response()->json(['message' => 'Only a publish from the last '.SitePublisher::UNDO_MINUTES.' minutes can be taken back.'], 409);
        }

        SitePublisher::undo($key, $undo);

        return response()->json(['page' => SitePages::pageOf($key)]);
    }

    /**
     * Puts an earlier version of the page back into the draft. Nothing goes live until she publishes it.
     */
    public function restore(SitePageKey $key, int $version): JsonResponse
    {
        $page = SitePage::query()->where('key', $key)->firstOrFail();
        SitePublisher::restore($page, $page->versions()->whereKey($version)->firstOrFail());

        return response()->json(['page' => SitePages::pageOf($key)]);
    }

    /**
     * Her portrait, stored as WebP. The draft takes its id when she saves the page.
     */
    public function portrait(Request $request): JsonResponse
    {
        $request->validate(
            ['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240']],
            ['image.mimes' => 'That isn’t a picture we can use. Try a JPG, PNG or WebP.', 'image.max' => 'That picture is too big. Try one under 10 MB.'],
        );

        $media = ImageStore::store($request->file('image'), self::PORTRAIT_FOLDER, self::PORTRAIT_SIDE, $request->user(), 'image');

        return response()->json(['id' => $media->id, 'src' => WorkPresenter::mediaUrl($media->disk, $media->path)], 201);
    }

    /**
     * Opens a store or pre-order address now and records whether it opened. Only public web addresses are opened.
     */
    public function linkCheck(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2048']]);
        $url = SitePageDraft::address($data['url']);

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(['url' => 'That isn’t a web address.']);
        }

        if (! SafeFetch::isPublic($url)) {
            throw ValidationException::withMessages(['url' => 'That address isn’t a public web address.']);
        }

        $check = SitePages::checkLink($url);

        return response()->json([
            'ok' => $check->ok,
            'checkedOn' => $check->checked_at->setTimezone((string) config('eyiwetemi.timezone'))->format('Y-m-d'),
        ]);
    }
}
