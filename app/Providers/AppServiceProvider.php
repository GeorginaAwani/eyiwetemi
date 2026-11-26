<?php

namespace App\Providers;

use App\Models\Character;
use App\Models\Comment;
use App\Models\Note;
use App\Models\Series;
use App\Models\SitePage;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WorldEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
            'user' => User::class,
        ]);
    }
}
