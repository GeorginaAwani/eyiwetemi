<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/* scheduled chapters come out first, so the newsletter and the announcement that follow include them */
Schedule::command('eyiwetemi:publish-due')->everyMinute()->withoutOverlapping();
Schedule::command('eyiwetemi:newsletter-send')->everyMinute()->withoutOverlapping();
Schedule::command('eyiwetemi:announce')->everyMinute()->withoutOverlapping();

/* the store and pre-order links on In print are checked each morning, so a broken one shows on the desk */
Schedule::command('eyiwetemi:check-links')->dailyAt('06:00')->timezone(config('eyiwetemi.timezone'));

/* the writer's mail about comments: a daily digest of readers', and reviewers' notes once a visit has ended */
Schedule::command('eyiwetemi:comments-digest')->dailyAt('20:00')->timezone(config('eyiwetemi.timezone'));
Schedule::command('eyiwetemi:review-mail')->everyFiveMinutes()->withoutOverlapping();

/*
| Nightly clean-up (approved pruning plan). Times are WAT.
*/
Schedule::command('model:prune')->dailyAt('03:00')->timezone(config('eyiwetemi.timezone'));
Schedule::command('queue:prune-failed --hours=720')->dailyAt('03:10')->timezone(config('eyiwetemi.timezone'));
Schedule::command('queue:prune-batches --hours=720')->dailyAt('03:15')->timezone(config('eyiwetemi.timezone'));
Schedule::command('auth:clear-resets')->dailyAt('03:20')->timezone(config('eyiwetemi.timezone'));

Schedule::call(function (): void {
    DB::table('notifications')
        ->whereNotNull('read_at')
        ->where('read_at', '<', now()->subMonths(12))
        ->delete();

    DB::table('cache')->where('expiration', '<', now()->getTimestamp())->delete();
})->name('prune-read-notifications-and-expired-cache')->dailyAt('03:30')->timezone(config('eyiwetemi.timezone'));
