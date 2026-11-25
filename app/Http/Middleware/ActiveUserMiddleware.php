<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActiveUserMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && \Illuminate\Support\Facades\Auth::guard('web')->check()
            && !app(\App\Services\SessionSecurity::class)->valid($request, \Illuminate\Support\Facades\Auth::guard('web')->user())) {
            app(\App\Services\SessionSecurity::class)->invalidateCurrent($request);
            abort(401);
        }
        if (!$request->user()?->isActive()) {
            app(\App\Services\SecurityAudit::class)->denied($request->user(),'active_account');
            $request->user()?->tokens()->delete();
            abort(403, 'Akun sedang tidak aktif.');
        }
        if (config('security.email_otp') && $request->user()->email && !$request->user()->email_verified_at) {
            abort(403, 'Email belum terverifikasi. Masuk kembali melalui website untuk verifikasi.');
        }

        return $next($request);
    }
}
