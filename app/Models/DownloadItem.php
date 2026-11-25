<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DownloadItem extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'download_session_id', 'post_media_id',
    ];

    public function downloadSession(): BelongsTo
    {
        return $this->belongsTo(DownloadSession::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(PostMedia::class, 'post_media_id');
    }
}
