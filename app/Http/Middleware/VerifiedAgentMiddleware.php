<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifiedAgentMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user?->hasRole('agent')) {
            app(\App\Services\SecurityAudit::class)->denied($user,'role');
            abort(403, 'Akses khusus Agent.');
        }

        if (!$user->agentProfile?->isVerified()) {
            app(\App\Services\SecurityAudit::class)->denied($user,'agent_verification');
            abort(403, 'Agent belum diverifikasi.');
        }

        if (!$user->branch_id || $user->branch?->status !== 'active') {
            app(\App\Services\SecurityAudit::class)->denied($user,'branch');
            abort(403, 'Agent belum terhubung ke cabang aktif.');
        }

        return $next($request);
    }
}
