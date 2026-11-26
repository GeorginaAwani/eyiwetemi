<?php

use App\Http\Controllers\Admin\SectionController;
use App\Http\Controllers\Admin\SeriesController;
use App\Http\Controllers\Admin\SignInController;
use App\Http\Controllers\Admin\WorkController;
use App\Http\Controllers\Admin\WorkCoverController;
use App\Http\Controllers\Admin\WorkReviewerController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

/*
| The admin, on its own host (admin.eyiwetemi.com). Loaded before the reading site's routes so its paths win here.
*/

Route::get('/sign-in', [SignInController::class, 'show'])->name('signin');

Route::middleware('throttle:20,1')->prefix('sign-in')->name('signin.')->group(function () {
    Route::post('/email', [SignInController::class, 'email'])->name('email');
    Route::post('/password', [SignInController::class, 'password'])->name('password');
    Route::post('/code', [SignInController::class, 'code'])->name('code');
    Route::post('/backup', [SignInController::class, 'backup'])->name('backup');
    Route::post('/link', [SignInController::class, 'sendLink'])->middleware('throttle:4,1,admin-link')->name('link');
    Route::post('/forgot', [SignInController::class, 'forgot'])->middleware('throttle:4,1,admin-forgot')->name('forgot');
    Route::post('/new-password', [SignInController::class, 'reset'])->name('reset');
    Route::delete('/new-password', [SignInController::class, 'cancelReset'])->name('reset.cancel');
});

Route::middleware('throttle:20,1')->group(function () {
    Route::get('/link/{token}', [SignInController::class, 'openLink'])->where('token', '[A-Za-z0-9]{64}')->name('link.open');
    Route::get('/password/{token}', [SignInController::class, 'openReset'])->name('password.open');
    Route::get('/auth/google', [SignInController::class, 'google'])->name('google');
    Route::get('/auth/google/callback', [SignInController::class, 'googleCallback'])->name('google.callback');
});

Route::middleware(EnsureAdmin::class)->group(function () {
    Route::get('/', [SectionController::class, 'desk'])->name('desk');
    Route::get('/{section}', [SectionController::class, 'show'])
        ->whereIn('section', ['notes', 'comments', 'reviewers', 'stats', 'newsletter', 'site'])
        ->name('section');

    Route::get('/works', [WorkController::class, 'index'])->name('works.index');
    Route::post('/works', [WorkController::class, 'store'])->name('works.store');
    Route::get('/works/title-taken', [WorkController::class, 'titleTaken'])->name('works.title-taken');
    Route::get('/works/{work:slug}', [WorkController::class, 'show'])->name('works.show');
    Route::patch('/works/{work:slug}', [WorkController::class, 'update'])->name('works.update');
    Route::delete('/works/{work:slug}', [WorkController::class, 'destroy'])->name('works.destroy');
    Route::post('/works/{work:slug}/cover', [WorkCoverController::class, 'store'])->name('works.cover.store');
    Route::delete('/works/{work:slug}/cover', [WorkCoverController::class, 'destroy'])->name('works.cover.destroy');
    Route::post('/works/{work:slug}/reviewers', [WorkReviewerController::class, 'store'])->name('works.reviewers.store');
    Route::delete('/works/{work:slug}/reviewers/{kind}/{id}', [WorkReviewerController::class, 'destroy'])
        ->whereIn('kind', ['user', 'invite'])->whereNumber('id')->name('works.reviewers.destroy');
    Route::get('/works/{work:slug}/write/{item}', [WorkController::class, 'write'])->whereNumber('item')->name('works.write');

    Route::post('/series', [SeriesController::class, 'store'])->name('series.store');
    Route::patch('/series/{series}', [SeriesController::class, 'update'])->name('series.update');

    Route::post('/sign-out', [SignInController::class, 'signOut'])->name('signout');
    Route::post('/forget-device', [SignInController::class, 'forgetDevice'])->name('device.forget');
});
