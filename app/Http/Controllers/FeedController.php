<?php

namespace App\Http\Controllers;

use App\Support\Feed;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class FeedController extends Controller
{
    /**
     * The RSS page: pick a feed and copy its address.
     */
    public function index(): InertiaResponse
    {
        $host = rtrim((string) preg_replace('~^https?://~', '', url('/')), '/');

        return Inertia::render('Rss', [
            'feeds' => [
                ['label' => 'Everything', 'url' => $host.'/feed'],
                ...array_map(fn (string $key, array $feed): array => ['label' => $feed['label'], 'url' => $host.'/feed/'.$key], array_keys(Feed::KINDS), Feed::KINDS),
            ],
        ]);
    }

    /**
     * A feed, as RSS.
     */
    public function show(?string $kind = null): Response
    {
        $feed = new Feed($kind);

        return response()
            ->view('feed', ['title' => $feed->title(), 'link' => url('/'), 'self' => url()->current(), 'entries' => $feed->entries()])
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}
