<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Safe on local HTTP too; HTTPS-only policies require deployment review. */
class SecurityResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        if (str_starts_with($response->headers->get('Content-Type', ''), 'text/html')
            && !$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy',
                "frame-src 'self' https://www.tiktok.com https://www.instagram.com; frame-ancestors 'self'; object-src 'none'; base-uri 'self'");
        }
        $response->headers->set('Referrer-Policy', $request->routeIs('google.callback', 'password.reset', 'account.email.confirm') ? 'no-referrer' : 'same-origin');
        // Dynamic responses include per-viewer flags, session/CSRF or private data.
        // Static versioned assets remain served independently by the web server.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
