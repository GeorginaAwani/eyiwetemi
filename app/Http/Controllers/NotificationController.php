<?php

namespace App\Http\Controllers;

use App\Support\NotificationFeed;
use App\Support\ProfilePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notifications: the page (All and Following), older pages, the bell's short list, and marking read.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $feed = new NotificationFeed($request->user());

        return Inertia::render('Notifications', [
            'tab' => $request->query('tab') === 'following' ? 'following' : 'all',
            'total' => $feed->total(),
            'unread' => $feed->unread(),
            'page' => $feed->page(),
            'following' => (new ProfilePage($request->user()))->list('following'),
        ]);
    }

    public function older(Request $request): JsonResponse
    {
        $request->validate(['before' => ['required', 'uuid']]);

        return response()->json((new NotificationFeed($request->user()))->page($request->string('before')->toString()));
    }

    public function menu(Request $request): JsonResponse
    {
        $feed = new NotificationFeed($request->user());

        return response()->json(['unread' => $feed->unread(), 'rows' => $feed->latest()]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->unreadNotifications()->whereKey($id)->update(['read_at' => now()]);

        return response()->json(['unread' => (new NotificationFeed($request->user()))->unread()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }
}
