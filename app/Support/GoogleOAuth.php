<?php

namespace App\Support;

final class GoogleOAuth
{
    public static function configured(): bool
    {
        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            if (!is_string(config('services.google.'.$key)) || trim(config('services.google.'.$key)) === '') return false;
        }
        $uri = parse_url(config('services.google.redirect'));
        return is_array($uri) && !empty($uri['host']) && !isset($uri['user']) && !isset($uri['pass']) && !isset($uri['query']) && !isset($uri['fragment'])
            && ($uri['path'] ?? '') === '/auth/google/callback'
            && (($uri['scheme'] ?? '') === 'https' || (($uri['scheme'] ?? '') === 'http'
                && in_array($uri['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)));
    }

    /** Only fixed local destinations; never accept an absolute URL or arbitrary query. */
    public static function returnPath(mixed $path): string
    {
        return is_string($path) && preg_match('~\A(?:/|/search|/posts/[1-9][0-9]*|/agents/[1-9][0-9]*)\z~D', $path) ? $path : '/';
    }
}
