<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Work;
use App\Support\ShareCard;
use App\Support\SiteSharing;
use Closure;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * The share cards link previews pick up: the site's, and each work's that readers can see. Her own picture when she has one,
 * otherwise the drawn card.
 */
class ShareCardController extends Controller
{
    public function site(): Response
    {
        return $this->picture(SiteSharing::ownPicture(null), fn (): string => ShareCard::site());
    }

    public function work(string $slug): Response
    {
        $work = Work::query()->listed()->with('cover')->where('slug', $slug)->first();

        abort_if($work === null, 404);

        return $this->picture(SiteSharing::ownPicture($slug), fn (): string => ShareCard::work($work));
    }

    /**
     * @param  Closure(): string  $draw
     */
    private function picture(?Media $own, Closure $draw): Response
    {
        $bytes = $own !== null ? (string) Storage::disk($own->disk)->get($own->path) : $draw();

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
