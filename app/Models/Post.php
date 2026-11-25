<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'listing_id',
        'caption',
        'content_type',
        'status',
        'moderation_status',
        'moderated_by',
        'moderated_at',
        'moderation_reason',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'moderated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('sort_order');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function saves(): HasMany
    {
        return $this->hasMany(Save::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(PostView::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class);
    }

    public function downloadSessions(): HasMany
    {
        return $this->hasMany(DownloadSession::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->where('moderation_status', 'active')
            ->whereNotNull('published_at');
    }
}
