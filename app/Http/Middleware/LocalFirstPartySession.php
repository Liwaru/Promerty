<?php

namespace App\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/** Keep Sanctum's pipeline, adding only the exact local development origin. */
class LocalFirstPartySession extends EnsureFrontendRequestsAreStateful
{
    public static function fromFrontend($request): bool
    {
        if(parent::fromFrontend($request))return true;
        if(!app()->environment('local')||$request->getPort()!==8000
            ||!in_array($request->getHost(),['localhost','127.0.0.1'],true)
            ||!in_array($request->server('REMOTE_ADDR'),['127.0.0.1','::1'],true))return false;
        $source=$request->headers->get('origin')?:$request->headers->get('referer');
        if(!$source)return false;
        $url=parse_url($source);if(!$url||!isset($url['scheme'],$url['host']))return false;
        $port=$url['port']??($url['scheme']==='https'?443:80);
        return $url['scheme']===$request->getScheme()&&strtolower($url['host'])===$request->getHost()&&$port===$request->getPort();
    }
}
