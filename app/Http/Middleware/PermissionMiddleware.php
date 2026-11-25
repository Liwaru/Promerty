<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission($permission)) {
            app(\App\Services\SecurityAudit::class)->denied($user,'permission');
            abort(403, 'Permission tidak tersedia.');
        }

        return $next($request);
    }
}
