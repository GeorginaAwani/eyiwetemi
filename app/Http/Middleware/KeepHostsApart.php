<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin host serves only admin pages: the reading site's pages (and Fortify's) don't answer there.
 */
class KeepHostsApart
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getHost() === config('eyiwetemi.admin_domain') && ! $request->routeIs('admin.*')) {
            abort(404);
        }

        return $next($request);
    }
}
