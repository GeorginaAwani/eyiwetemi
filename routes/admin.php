<?php

use App\Http\Controllers\Admin\ChapterController;
use App\Http\Controllers\Admin\CommentController;
use App\Http\Controllers\Admin\NewsletterController;
use App\Http\Controllers\Admin\NoteController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PublishController;
use App\Http\Controllers\Admin\ReviewerController;
use App\Http\Controllers\Admin\SectionController;
use App\Http\Controllers\Admin\SeriesController;
use App\Http\Controllers\Admin\SignInController;
use App\Http\Controllers\Admin\SiteContactController;
use App\Http\Controllers\Admin\SiteController;
use App\Http\Controllers\Admin\SiteNoticeController;
use App\Http\Controllers\Admin\SitePinController;
use App\Http\Controllers\Admin\SiteSharingController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\Admin\SweepController;
use App\Http\Controllers\Admin\WorkController;
use App\Http\Controllers\Admin\WorkCoverController;
use App\Http\Controllers\Admin\WorkReviewerController;
use App\Http\Controllers\Admin\WriteController;
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

    Route::get('/comments', [CommentController::class, 'index'])->name('comments.index');
    Route::get('/comments/readers', [CommentController::class, 'readers'])->name('comments.readers');
    Route::get('/comments/readers/{item}', [CommentController::class, 'readerItem'])->whereNumber('item')->name('comments.readers.item');
    Route::get('/comments/reviewers', [CommentController::class, 'reviewers'])->name('comments.reviewers');
    Route::post('/comments/seen', [CommentController::class, 'seen'])->name('comments.seen');
    Route::patch('/comments/mail', [CommentController::class, 'mail'])->name('comments.mail');
    Route::post('/comments/{comment}/reply', [CommentController::class, 'reply'])->whereNumber('comment')->name('comments.reply');
    Route::put('/comments/{comment}/like', [CommentController::class, 'like'])->whereNumber('comment')->name('comments.like');
    Route::delete('/comments/{comment}/like', [CommentController::class, 'unlike'])->whereNumber('comment')->name('comments.unlike');
    Route::patch('/comments/{comment}/hide', [CommentController::class, 'hide'])->whereNumber('comment')->name('comments.hide');
    Route::patch('/comments/{comment}/status', [CommentController::class, 'status'])->whereNumber('comment')->name('comments.status');

    Route::get('/reviewers', [ReviewerController::class, 'index'])->name('reviewers.index');
    Route::get('/reviewers/works', [ReviewerController::class, 'works'])->name('reviewers.works');
    Route::post('/reviewers/invites', [ReviewerController::class, 'store'])->name('reviewers.invites.store');
    Route::post('/reviewers/invites/{invite}/resend', [ReviewerController::class, 'resend'])->whereNumber('invite')->name('reviewers.invites.resend');
    Route::delete('/reviewers/invites/{invite}', [ReviewerController::class, 'cancel'])->whereNumber('invite')->name('reviewers.invites.cancel');
    Route::patch('/reviewers/invites/{invite}/works', [ReviewerController::class, 'inviteWorks'])->whereNumber('invite')->name('reviewers.invites.works');
    Route::patch('/reviewers/{user}/works', [ReviewerController::class, 'userWorks'])->whereNumber('user')->name('reviewers.works.user');
    Route::delete('/reviewers/{user}', [ReviewerController::class, 'remove'])->whereNumber('user')->name('reviewers.remove');

    Route::get('/notes', [NoteController::class, 'index'])->name('notes.index');
    Route::post('/notes', [NoteController::class, 'store'])->name('notes.store');
    Route::post('/notes/seen', [NoteController::class, 'seen'])->name('notes.seen');
    Route::patch('/notes/{note}', [NoteController::class, 'update'])->whereNumber('note')->name('notes.update');
    Route::delete('/notes/{note}', [NoteController::class, 'destroy'])->whereNumber('note')->name('notes.destroy');
    Route::post('/notes/{note}/photo', [NoteController::class, 'photo'])->whereNumber('note')->name('notes.photo');
    Route::post('/notes/{note}/publish', [NoteController::class, 'publish'])->whereNumber('note')->name('notes.publish');
    Route::post('/notes/{note}/undo', [NoteController::class, 'undo'])->whereNumber('note')->name('notes.undo');
    Route::post('/notes/{note}/schedule', [NoteController::class, 'schedule'])->whereNumber('note')->name('notes.schedule');
    Route::delete('/notes/{note}/schedule', [NoteController::class, 'unschedule'])->whereNumber('note')->name('notes.unschedule');

    Route::get('/newsletter', [NewsletterController::class, 'index'])->name('newsletter.index');
    Route::put('/newsletter/weekly', [NewsletterController::class, 'weekly'])->name('newsletter.weekly');
    Route::patch('/newsletter/next', [NewsletterController::class, 'updateNext'])->name('newsletter.next');
    Route::patch('/newsletter/next/items/{key}', [NewsletterController::class, 'updateItem'])->where('key', 'i[0-9]+')->name('newsletter.items');
    Route::get('/newsletter/subscribers', [NewsletterController::class, 'subscribers'])->name('newsletter.subscribers');
    Route::delete('/newsletter/subscribers/{subscriber}', [NewsletterController::class, 'removeSubscriber'])->whereNumber('subscriber')->name('newsletter.subscribers.destroy');
    Route::patch('/newsletter/welcome', [NewsletterController::class, 'welcome'])->name('newsletter.welcome');
    Route::get('/newsletter/preview', [NewsletterController::class, 'preview'])->name('newsletter.preview');
    Route::post('/newsletter/test', [NewsletterController::class, 'test'])->name('newsletter.test');

    Route::get('/site', [SiteController::class, 'index'])->name('site.index');
    Route::patch('/site/pages/{key}', [SiteController::class, 'update'])->whereIn('key', ['about', 'in_print', 'privacy', 'terms'])->name('site.pages.update');
    Route::post('/site/pages/{key}/publish', [SiteController::class, 'publish'])->whereIn('key', ['about', 'in_print', 'privacy', 'terms'])->name('site.pages.publish');
    Route::post('/site/pages/{key}/undo', [SiteController::class, 'undo'])->whereIn('key', ['about', 'in_print', 'privacy', 'terms'])->name('site.pages.undo');
    Route::post('/site/pages/{key}/versions/{version}/restore', [SiteController::class, 'restore'])
        ->whereIn('key', ['about', 'in_print', 'privacy', 'terms'])->whereNumber('version')->name('site.pages.restore');
    Route::post('/site/portrait', [SiteController::class, 'portrait'])->name('site.portrait');
    Route::post('/site/link-check', [SiteController::class, 'linkCheck'])->middleware('throttle:30,1')->name('site.link-check');

    Route::put('/site/home-pin', [SitePinController::class, 'update'])->name('site.pin.update');
    Route::patch('/site/home-pin', [SitePinController::class, 'date'])->name('site.pin.date');
    Route::delete('/site/home-pin', [SitePinController::class, 'destroy'])->name('site.pin.destroy');
    Route::post('/site/notice', [SiteNoticeController::class, 'store'])->name('site.notice.store');
    Route::patch('/site/notice/{notice}', [SiteNoticeController::class, 'update'])->whereNumber('notice')->name('site.notice.update');
    Route::post('/site/notice/{notice}/down', [SiteNoticeController::class, 'down'])->whereNumber('notice')->name('site.notice.down');
    Route::patch('/site/contact', [SiteContactController::class, 'update'])->name('site.contact');
    Route::patch('/site/sharing', [SiteSharingController::class, 'update'])->name('site.sharing');
    Route::post('/site/sharing/picture', [SiteSharingController::class, 'picture'])->name('site.sharing.picture');

    Route::get('/stats', [StatsController::class, 'show'])->name('stats');
    Route::get('/stats/range', [StatsController::class, 'range'])->name('stats.range');

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
    Route::post('/works/{work:slug}/plan/characters', [PlanController::class, 'storeCharacter'])->name('plan.characters.store');
    Route::patch('/works/{work:slug}/plan/characters/{character}', [PlanController::class, 'updateCharacter'])->whereNumber('character')->name('plan.characters.update');
    Route::delete('/works/{work:slug}/plan/characters/{character}', [PlanController::class, 'destroyCharacter'])->whereNumber('character')->name('plan.characters.destroy');
    Route::post('/works/{work:slug}/plan/links', [PlanController::class, 'storeLink'])->name('plan.links.store');
    Route::patch('/works/{work:slug}/plan/links/{link}', [PlanController::class, 'updateLink'])->whereNumber('link')->name('plan.links.update');
    Route::delete('/works/{work:slug}/plan/links/{link}', [PlanController::class, 'destroyLink'])->whereNumber('link')->name('plan.links.destroy');
    Route::post('/works/{work:slug}/plan/groups', [PlanController::class, 'storeGroup'])->name('plan.groups.store');
    Route::patch('/works/{work:slug}/plan/groups/{group}', [PlanController::class, 'updateGroup'])->whereNumber('group')->name('plan.groups.update');
    Route::delete('/works/{work:slug}/plan/groups/{group}', [PlanController::class, 'destroyGroup'])->whereNumber('group')->name('plan.groups.destroy');
    Route::post('/works/{work:slug}/plan/entries', [PlanController::class, 'storeEntry'])->name('plan.entries.store');
    Route::patch('/works/{work:slug}/plan/entries/{entry}', [PlanController::class, 'updateEntry'])->whereNumber('entry')->name('plan.entries.update');
    Route::delete('/works/{work:slug}/plan/entries/{entry}', [PlanController::class, 'destroyEntry'])->whereNumber('entry')->name('plan.entries.destroy');
    Route::post('/works/{work:slug}/plan/threads', [PlanController::class, 'storeThread'])->name('plan.threads.store');
    Route::patch('/works/{work:slug}/plan/threads/{thread}', [PlanController::class, 'updateThread'])->whereNumber('thread')->name('plan.threads.update');
    Route::delete('/works/{work:slug}/plan/threads/{thread}', [PlanController::class, 'destroyThread'])->whereNumber('thread')->name('plan.threads.destroy');
    Route::post('/works/{work:slug}/plan/points', [PlanController::class, 'storePoint'])->name('plan.points.store');
    Route::patch('/works/{work:slug}/plan/points/{point}', [PlanController::class, 'updatePoint'])->whereNumber('point')->name('plan.points.update');
    Route::delete('/works/{work:slug}/plan/points/{point}', [PlanController::class, 'destroyPoint'])->whereNumber('point')->name('plan.points.destroy');
    Route::post('/works/{work:slug}/plan/research', [PlanController::class, 'storeResearch'])->name('plan.research.store');
    Route::patch('/works/{work:slug}/plan/research/{research}', [PlanController::class, 'updateResearch'])->whereNumber('research')->name('plan.research.update');
    Route::delete('/works/{work:slug}/plan/research/{research}', [PlanController::class, 'destroyResearch'])->whereNumber('research')->name('plan.research.destroy');
    Route::patch('/works/{work:slug}/plan/notes', [PlanController::class, 'updateNotes'])->name('plan.notes.update');
    Route::get('/works/{work:slug}/write/{item}', [WriteController::class, 'show'])->whereNumber('item')->name('works.write');
    Route::patch('/works/{work:slug}/write/{item}', [WriteController::class, 'save'])->whereNumber('item')->name('works.write.save');
    Route::post('/works/{work:slug}/write/{item}/image', [WriteController::class, 'image'])->whereNumber('item')->name('works.write.image');
    Route::post('/works/{work:slug}/write/{item}/publish', [PublishController::class, 'publish'])->whereNumber('item')->name('works.publish');
    Route::post('/works/{work:slug}/write/{item}/schedule', [PublishController::class, 'schedule'])->whereNumber('item')->name('works.schedule');
    Route::delete('/works/{work:slug}/write/{item}/schedule', [PublishController::class, 'unschedule'])->whereNumber('item')->name('works.unschedule');
    Route::post('/works/{work:slug}/write/{item}/unpublish', [PublishController::class, 'unpublish'])->whereNumber('item')->name('works.unpublish');
    Route::get('/works/{work:slug}/write/{item}/versions/{version}', [PublishController::class, 'version'])->whereNumber(['item', 'version'])->name('works.version');
    Route::post('/works/{work:slug}/write/{item}/versions/{version}/restore', [PublishController::class, 'restore'])->whereNumber(['item', 'version'])->name('works.version.restore');
    Route::post('/works/{work:slug}/sweep/names', [SweepController::class, 'store'])->name('sweep.names.store');
    Route::delete('/works/{work:slug}/sweep/names/{name}', [SweepController::class, 'destroy'])->whereNumber('name')->name('sweep.names.destroy');
    Route::patch('/works/{work:slug}/sweep/mark', [SweepController::class, 'mark'])->name('sweep.mark');
    Route::get('/works/{work:slug}/sweep/pages', [SweepController::class, 'pages'])->name('sweep.pages');
    Route::get('/works/{work:slug}/opening', [ChapterController::class, 'opening'])->name('works.opening');
    Route::patch('/works/{work:slug}/opening', [ChapterController::class, 'saveOpening'])->name('works.opening.save');
    Route::post('/works/{work:slug}/items', [ChapterController::class, 'store'])->name('works.items.store');
    Route::patch('/works/{work:slug}/items/order', [ChapterController::class, 'order'])->name('works.items.order');
    Route::post('/works/{work:slug}/items/borrow', [ChapterController::class, 'borrow'])->name('works.items.borrow');
    Route::delete('/works/{work:slug}/items/{item}', [ChapterController::class, 'release'])->whereNumber('item')->name('works.items.release');

    Route::post('/series', [SeriesController::class, 'store'])->name('series.store');
    Route::patch('/series/{series}', [SeriesController::class, 'update'])->name('series.update');

    Route::post('/sign-out', [SignInController::class, 'signOut'])->name('signout');
    Route::post('/forget-device', [SignInController::class, 'forgetDevice'])->name('device.forget');
});
