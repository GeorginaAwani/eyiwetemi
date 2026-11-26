<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InPrintController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SeriesController;
use App\Http\Controllers\WorkController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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

Route::post('/newsletter', [NewsletterController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('newsletter.subscribe');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
