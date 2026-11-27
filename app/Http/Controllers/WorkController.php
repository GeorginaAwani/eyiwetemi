<?php

namespace App\Http\Controllers;

use App\Enums\WorkKind;
use App\Models\SlugHistory;
use App\Models\Work;
use App\Support\ReaderPage;
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
        $work = $this->find($slug);

        if ($work instanceof RedirectResponse) {
            return $work;
        }

        abort_unless(Gate::allows('view', $work), 404);

        /* single stories, poems and essays open straight into the reader */
        if (! in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true)) {
            $reader = new ReaderPage($work);
            abort_unless($reader->row() !== null && $reader->isOut($reader->row()['item']), 404);

            return Inertia::render('Reader', $reader->toArray());
        }

        return Inertia::render('Work', (new WorkPage($work))->toArray());
    }

    /**
     * A chapter of a novel, or a piece of a collection. One that was taken down says so; one past the free
     * chapters of a book in print goes to where to buy it.
     */
    public function chapter(string $slug, int $number): Response|RedirectResponse
    {
        $work = $this->find($slug);

        if ($work instanceof RedirectResponse) {
            return redirect()->to(parse_url($work->getTargetUrl(), PHP_URL_PATH).'/'.$number, 301);
        }

        abort_unless(Gate::allows('view', $work) && in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true), 404);

        $reader = new ReaderPage($work, $number);
        $row = $reader->row();
        abort_if($row === null || $row['item']->published_at === null, 404);

        if ($reader->isInPrintOnly($number)) {
            return redirect()->to(WorkPresenter::href($work).'#in-print');
        }

        if (! $reader->isOut($row['item'])) {
            return Inertia::render('Reader', ['unavailable' => [
                'work' => ['title' => $work->title, 'href' => WorkPresenter::href($work)],
                'label' => 'Chapter '.$number,
            ]]);
        }

        return Inertia::render('Reader', $reader->toArray());
    }

    /**
     * The work at this address, or a redirect when the address is an old one.
     */
    private function find(string $slug): Work|RedirectResponse
    {
        $work = Work::query()->with([...WorkPresenter::with(), 'genres'])->firstWhere('slug', $slug);

        if ($work !== null) {
            return $work;
        }

        $moved = SlugHistory::query()->where('sluggable_type', 'work')->where('slug', $slug)->latest('id')->first()?->sluggable;
        /* an old address of a work you can't see is not found either, so its new address stays private */
        abort_if($moved === null || ! Gate::allows('view', $moved), 404);

        return redirect()->to(WorkPresenter::href($moved), 301);
    }
}
