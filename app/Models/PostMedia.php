<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostMedia extends Model
{
    protected $table = 'post_media';

    protected $fillable = [
        'post_id',
        'media_type',
        'file_url',
        'thumbnail_url',
        'sort_order',
        'file_size_bytes',
        'duration_seconds',
        'width',
        'height',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'file_size_bytes' => 'integer',
            'duration_seconds' => 'decimal:2',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function downloadItems(): HasMany
    {
        return $this->hasMany(DownloadItem::class);
    }
}
