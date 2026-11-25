<?php

namespace App\Services;

final class ExternalListingVideo
{
    /** Return only provider-controlled URL components. Never resolve or fetch the URL. */
    public static function parse(string $url): ?array
    {
        if (strlen($url) > 512 || preg_match('~[\x00-\x20\x7f]~', $url) || str_contains($url, '\\')) {
            return null;
        }

        $parts = parse_url($url);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        if (in_array($host, ['www.tiktok.com', 'tiktok.com'], true)
            && preg_match('~^/@([A-Za-z0-9_.]{1,24})/video/([1-9][0-9]{17,19})/?$~D', $path, $match)) {
            return ['provider' => 'tiktok', 'id' => $match[2],
                'url' => 'https://www.tiktok.com/@'.$match[1].'/video/'.$match[2]];
        }

        if (in_array($host, ['www.instagram.com', 'instagram.com'], true)
            && preg_match('~^/reel/([A-Za-z0-9_-]{5,30})/?$~D', $path, $match)) {
            return ['provider' => 'instagram', 'id' => $match[1],
                'url' => 'https://www.instagram.com/reel/'.$match[1].'/'];
        }

        return null;
    }
}
