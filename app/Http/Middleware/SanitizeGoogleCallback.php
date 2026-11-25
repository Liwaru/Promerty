<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** StartSession must not retain the callback authorization code in its previous-URL history. */
class SanitizeGoogleCallback
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->is('auth/google/callback')) return $next($request);
        try { return $next($request); }
        finally {
            $request->query->replace([]);
            $request->server->set('QUERY_STRING', '');
        }
    }
}
