<?php

namespace App\Http\Middleware;

use App\Services\SessionSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnforceSessionVersion
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->hasSession() && Auth::guard('web')->check()) {
            $security = app(SessionSecurity::class);
            if (!$security->valid($request, Auth::guard('web')->user())) {
                app(\App\Services\SecurityAudit::class)->denied(Auth::guard('web')->user(),'session');
                $security->invalidateCurrent($request);
                abort(401, 'Session expired. Please sign in again.');
            }
        }
        return $next($request);
    }
}
