<?php

namespace App\Support;

use App\Models\Work;
use Illuminate\Http\Request;

/**
 * What a public page's link preview says: the work's title and line on its own pages (the library address and its chapters),
 * and the site's otherwise, each with its share card as the picture.
 */
class ShareMeta
{
    /**
     * @return array{title: string, description: string, url: string, image: string}
     */
    public static function for(Request $request): array
    {
        $work = $request->routeIs('works.show', 'works.chapter')
            ? Work::query()->listed()->where('slug', (string) $request->route('slug'))->first(['id', 'title', 'slug', 'description'])
            : null;

        if ($work !== null) {
            return [
                'title' => $work->title,
                'description' => SiteSharing::workLine($work),
                'url' => $request->url(),
                'image' => url('/share/'.$work->slug.'.jpg'),
            ];
        }

        return [
            'title' => 'Eyiwetemi',
            'description' => SiteSharing::siteLine(),
            'url' => $request->url(),
            'image' => url('/share/site.jpg'),
        ];
    }
}
