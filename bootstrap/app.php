<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\KeepHostsApart;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /* only the site's own host and its subdomains (the admin) are answered, so links in emails can't be pointed elsewhere by a forged Host */
        $middleware->trustHosts();

        $middleware->web(append: [
            KeepHostsApart::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        /* the writing screen's and the diary's saves keep every space she typed (a space beside italics is part of the text) */
        $writing = fn (Request $request): bool => $request->isMethod('PATCH') && $request->is('works/*/write/*', 'notes/*');
        /* a reviewer's quote keeps the spaces at its edges: it must match the paragraph exactly */
        $reviewing = fn (Request $request): bool => $request->is('review/marks*');
        /* a site page's draft keeps what she typed, empty paragraphs and headings included, as the page's own rules check them */
        $sitePage = fn (Request $request): bool => $request->isMethod('PATCH') && $request->is('site/pages/*');
        $middleware->trimStrings(except: [$writing, $reviewing, $sitePage]);
        $middleware->convertEmptyStringsToNull(except: [$writing, $sitePage]);

        /* private pages: Home with the sign-in card; signing in carries on to the page they asked for */
        $middleware->redirectGuestsTo(function (Request $request): string {
            $request->session()->flash('signin', [
                'view' => 'main',
                'why' => $request->is('notifications*') ? 'Sign in to see your notifications' : 'Sign in to see your profile',
            ]);

            return route('home');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
