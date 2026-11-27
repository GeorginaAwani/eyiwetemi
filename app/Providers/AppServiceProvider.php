<?php

namespace App\Providers;

use App\Models\Character;
use App\Models\Comment;
use App\Models\Note;
use App\Models\PlotThread;
use App\Models\Series;
use App\Models\SitePage;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WorldEntry;
use App\Support\ReviewerInvites;
use App\Support\ShareMeta;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        /* the reading site's page shell carries the link preview: the work's own, or the site's */
        View::composer('app', fn (ViewInstance $view) => $view->with('shareMeta', ShareMeta::for(request())));

        /* a guest who opened a reviewer invite and then signed in or made an account is a reviewer now */
        Event::listen(Login::class, function (Login $login): void {
            if ($login->user instanceof User) {
                ReviewerInvites::acceptFromSession($login->user, request());
            }
        });

        Model::shouldBeStrict(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'work' => Work::class,
            'work_item' => WorkItem::class,
            'series' => Series::class,
            'note' => Note::class,
            'comment' => Comment::class,
            'site_page' => SitePage::class,
            'character' => Character::class,
            'world_entry' => WorldEntry::class,
            'plot_thread' => PlotThread::class,
            'user' => User::class,
        ]);
    }
}
