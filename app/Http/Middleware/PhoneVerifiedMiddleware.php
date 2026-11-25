<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PhoneVerifiedMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->phone_verified_at) {
            abort(403, 'Nomor HP harus diverifikasi terlebih dahulu.');
        }

        return $next($request);
    }
}
