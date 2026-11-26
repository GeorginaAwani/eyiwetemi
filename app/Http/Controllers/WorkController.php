<?php

namespace App\Http\Controllers;

use App\Enums\WorkKind;
use App\Models\SlugHistory;
use App\Models\Work;
use App\Support\WorkPage;
use App\Support\WorkPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkController extends Controller
{
    /**
     * A novel's or collection's page. An old address (from before a rename) moves to the current one;
     * a work you can't see is simply not found.
     */
    public function show(string $slug): Response|RedirectResponse
    {
        $work = Work::query()->with([...WorkPresenter::with(), 'genres'])->firstWhere('slug', $slug);

        if ($work === null) {
            $moved = SlugHistory::query()
                ->where('sluggable_type', 'work')
                ->where('slug', $slug)
                ->latest('id')
                ->first()?->sluggable;

            abort_if($moved === null, 404);

            return redirect()->to(WorkPresenter::href($moved), 301);
        }

        abort_unless(Gate::allows('view', $work), 404);

        /* single stories, poems and essays open straight into the reader, which comes with the next step */
        abort_unless(in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true), 404);

        return Inertia::render('Work', (new WorkPage($work))->toArray());
    }
}
