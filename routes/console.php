<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('eyiwetemi:announce')->everyMinute()->withoutOverlapping();

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
