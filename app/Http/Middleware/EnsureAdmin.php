<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin pages: only the author, and only after the admin's own sign-in (code or a remembered device) this session.
 * A reader's or reviewer's session never counts, even if it reaches this host.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isAuthor() && $request->session()->get('admin.verified') === true) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(401);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('admin.signin');
    }
}
