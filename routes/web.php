<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\UsernameController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\EmailsController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InPrintController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SeriesController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WorkController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/about', AboutController::class)->name('about');
Route::get('/library', [LibraryController::class, 'index'])->name('library');
Route::get('/library-rows', [LibraryController::class, 'rows'])->name('library.rows');
Route::get('/library/{slug}', [WorkController::class, 'show'])->name('works.show');
Route::get('/library/{slug}/{number}', [WorkController::class, 'chapter'])->whereNumber('number')->name('works.chapter');
Route::get('/notes', [NoteController::class, 'index'])->name('notes');
Route::get('/notes-older', [NoteController::class, 'older'])->name('notes.older');
Route::get('/in-print', InPrintController::class)->name('in-print');
Route::get('/privacy', PrivacyController::class)->name('privacy');
Route::get('/rss', [FeedController::class, 'index'])->name('rss');
Route::get('/feed/{kind?}', [FeedController::class, 'show'])->whereIn('kind', ['novels', 'stories', 'poetry', 'essays', 'notes'])->name('feed');
Route::get('/series/{slug}', [SeriesController::class, 'show'])->name('series.show');
Route::get('/search', SearchController::class)->middleware('throttle:60,1')->name('search');

/* yours: private pages, and likes and follows */
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::get('/settings', [ProfileController::class, 'settings'])->name('settings');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::get('/notifications/older', [NotificationController::class, 'older'])->name('notifications.older');
    Route::get('/notifications/menu', [NotificationController::class, 'menu'])->name('notifications.menu');
    Route::post('/notifications/read', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');
    Route::get('/profile/{which}', [ProfileController::class, 'list'])->whereIn('which', ['following', 'likes', 'comments'])->name('profile.list');

    Route::middleware('throttle:60,1')->group(function () {
        Route::put('/likes/{type}/{id}', [LikeController::class, 'store'])->whereIn('type', ['work', 'work_item', 'note', 'comment'])->whereNumber('id')->name('likes.store');
        Route::delete('/likes/{type}/{id}', [LikeController::class, 'destroy'])->whereIn('type', ['work', 'work_item', 'note', 'comment'])->whereNumber('id')->name('likes.destroy');
        Route::put('/follows/{type}/{id}', [FollowController::class, 'store'])->whereIn('type', ['work', 'series'])->whereNumber('id')->name('follows.store');
        Route::delete('/follows/{type}/{id}', [FollowController::class, 'destroy'])->whereIn('type', ['work', 'series'])->whereNumber('id')->name('follows.destroy');
    });

    Route::post('/comments', [CommentController::class, 'store'])->middleware('throttle:10,1')->name('comments.store');
});

Route::post('/newsletter', [NewsletterController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('newsletter.subscribe');

Route::middleware('throttle:30,1')->group(function () {
    Route::get('/emails/off', [EmailsController::class, 'off'])->name('emails.off');
    Route::get('/emails/confirm/{token}', [EmailsController::class, 'confirm'])->where('token', '[A-Za-z0-9]{32,64}')->name('emails.confirm');
    Route::get('/emails/{token}', [EmailsController::class, 'show'])->where('token', '[A-Za-z0-9]{32,64}')->name('emails.show');
    Route::put('/emails/{token}', [EmailsController::class, 'update'])->where('token', '[A-Za-z0-9]{32,64}')->name('emails.update');
    Route::delete('/emails/{token}', [EmailsController::class, 'destroy'])->where('token', '[A-Za-z0-9]{32,64}')->name('emails.destroy');
});

Route::get('/username-check', UsernameController::class)->middleware('throttle:60,1')->name('username.check');
Route::get('/reset-password/{token}', PasswordResetLinkController::class)->name('password.reset');

Route::middleware('throttle:10,1')->group(function () {
    Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
    Route::post('/auth/google/finish', [GoogleController::class, 'finish'])->name('google.finish');
});

// --- settings (account) ---
Route::middleware(['auth', 'throttle:30,1'])->prefix('settings')->name('settings.')->group(function () {
    Route::patch('/name', [SettingsController::class, 'name'])->name('name');
    Route::patch('/username', [SettingsController::class, 'username'])->name('username');
    Route::post('/email', [SettingsController::class, 'email'])->name('email');
    Route::post('/email/again', [SettingsController::class, 'resendEmail'])->middleware('throttle:3,1,settings-email-again')->name('email.again');
    Route::delete('/email/pending', [SettingsController::class, 'cancelEmail'])->name('email.cancel');
    Route::put('/password', [SettingsController::class, 'password'])->name('password');
    Route::post('/photo', [SettingsController::class, 'photo'])->name('photo');
    Route::put('/notifications', [SettingsController::class, 'notifications'])->name('notifications');
    Route::put('/reading', [SettingsController::class, 'reading'])->name('reading');
    Route::delete('/account', [SettingsController::class, 'destroy'])->name('destroy');
});

// The link in the email is opened from an inbox, so it needs the signature and no sign-in.
Route::get('/settings/email/confirm/{user}', [SettingsController::class, 'confirmEmail'])->middleware('signed')->name('settings.email.confirm');
// --- end settings ---
