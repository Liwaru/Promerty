<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'post_id',
        'user_id',
        'session_id',
        'watch_duration_seconds',
        'completed',
        'viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'watch_duration_seconds' => 'decimal:2',
            'completed' => 'boolean',
            'viewed_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
