<?php

namespace App\Http\Middleware;

use App\Services\SecurityAudit;
use Closure;
use Illuminate\Http\Request;

class AuditAuthorizationDenials
{
    public function handle(Request $request,Closure $next)
    {
        try{return $next($request);}
        finally{app(SecurityAudit::class)->flushDenials($request);}
    }
}
