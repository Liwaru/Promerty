<?php

namespace App\Services;

use App\Models\PostMedia;

class FeedMedia
{
    public static function isLocalMp4(PostMedia $media): bool
    {
        $path = AgentMedia::path($media) ?? self::localPath($media->file_url);
        return $media->media_type === 'video' && $path !== null
            && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'mp4';
    }

    public static function canPresent(PostMedia $media, string $contentType): bool
    {
        return $media->media_type === match ($contentType) {
            'photo' => 'image',
            'video' => 'video',
            default => null,
        }
            && self::url($media->file_url) !== null
            && (!str_starts_with($media->file_url, 'agent-private/') || AgentMedia::exists($media));
    }

    public static function localPath(?string $value): ?string
    {
        if ($value && str_starts_with($value, 'agent-private/')) return null;
        if (!$value || preg_match('~^[a-z][a-z0-9+.-]*:|^//~i', $value)) return null;
        $path = ltrim(rawurldecode($value), '/');
        if (str_starts_with($path, 'storage/')) $path = substr($path, 8);
        if (!$path || str_contains($path, '\\') || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) return null;
        return $path;
    }

    public static function url(?string $value): ?string
    {
        if (!$value) return null;
        if (preg_match('~^agent-private/([a-f0-9]{48}\.(?:jpg|png|webp|mp4))$~D',$value,$match)) return '/api/listing-media/'.$match[1];
        if (filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($value, PHP_URL_SCHEME)), ['https', 'http'], true)) return $value;
        $path = self::localPath($value);
        return $path ? '/storage/'.implode('/', array_map('rawurlencode', explode('/', $path))) : null;
    }
}
