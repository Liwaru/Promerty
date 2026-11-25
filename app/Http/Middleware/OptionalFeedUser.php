<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OptionalFeedUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('sanctum')->user();
        if ($user && $request->hasSession() && Auth::guard('web')->check()
            && !app(\App\Services\SessionSecurity::class)->valid($request,Auth::guard('web')->user())) {
            app(\App\Services\SessionSecurity::class)->invalidateCurrent($request);
            Auth::guard('sanctum')->forgetUser();
            $user=null;
        }
        abort_if($request->bearerToken() && !$user, 401, 'Sesi berakhir. Silakan masuk kembali.');
        abort_if($user && !$user->isActive(), 403, 'Akun sedang tidak aktif.');
        $request->setUserResolver(fn () => $user);
        return $next($request);
    }
}
