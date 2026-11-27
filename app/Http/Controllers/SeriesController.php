<?php

namespace App\Http\Controllers;

use App\Models\Series;
use App\Models\SlugHistory;
use App\Support\SeriesPage;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SeriesController extends Controller
{
    /**
     * A series' page. Old addresses move to the current one; a series with nothing public is not found.
     */
    public function show(string $slug): Response|RedirectResponse
    {
        $series = Series::query()->firstWhere('slug', $slug);

        if ($series === null) {
            $moved = SlugHistory::query()->where('sluggable_type', 'series')->where('slug', $slug)->latest('id')->first()?->sluggable;
            abort_if($moved === null || ! (new SeriesPage($moved))->isVisible(), 404);

            return redirect()->to('/series/'.$moved->slug, 301);
        }

        $page = new SeriesPage($series);
        abort_unless($page->isVisible(), 404);

        return Inertia::render('Series', $page->toArray());
    }
}
