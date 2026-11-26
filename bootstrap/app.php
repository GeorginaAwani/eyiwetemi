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
        $middleware->web(append: [
            KeepHostsApart::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        /* the writing screen's saves keep every space she typed (a space beside italics is part of the text) */
        $writing = fn (Request $request): bool => $request->isMethod('PATCH') && $request->is('works/*/write/*');
        $middleware->trimStrings(except: [$writing]);
        $middleware->convertEmptyStringsToNull(except: [$writing]);

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
