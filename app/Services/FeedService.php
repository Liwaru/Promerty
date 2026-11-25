<?php

namespace App\Services;

use App\Models\{Comment, Post, User};
use Illuminate\Database\Eloquent\Builder;

class FeedService
{
    public function visible(): Builder
    {
        return Post::visible()->where('published_at', '<=', now())
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->whereHas('listing', fn ($q) => $q->active()->whereHas('agent', fn ($a) => $a->where('status', 'active')));
    }

    public function withPresentationCandidates(Builder $query): Builder
    {
        return $query->whereHas('media', function ($media) {
            $media->whereNotNull('file_url')->where('file_url', '<>', '')
                ->where(fn ($types) => $types
                    ->where(fn ($photo) => $photo->where('posts.content_type', 'photo')->where('media_type', 'image'))
                    ->orWhere(fn ($video) => $video->where('posts.content_type', 'video')->where('media_type', 'video')));
        });
    }

    public static function activeComments(Builder $query): Builder
    {
        return $query->where('moderation_status', 'active')
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhereHas('parent', fn ($p) => $p->where('moderation_status', 'active')));
    }

    public function query(?User $viewer = null): Builder
    {
        $query = $this->visible()->with([
            'user.role', 'user.agentProfile', 'user.branch.agency',
            'listing.agent.role', 'listing.agent.agentProfile', 'listing.agent.branch.agency',
            'listing.category', 'listing.project.location', 'listing.secondaryProperty.location', 'media',
        ])->withCount([
            'likes', 'comments' => fn ($q) => self::activeComments($q),
            'saves', 'shares', 'views', 'downloadSessions',
        ]);
        if ($viewer) {
            $query->withExists([
                'likes as viewer_liked' => fn ($q) => $q->where('user_id', $viewer->id),
                'saves as viewer_saved' => fn ($q) => $q->where('user_id', $viewer->id),
            ]);
        }
        return $query;
    }

    public function post(int $id, ?User $viewer = null): Post
    {
        return $this->query($viewer)->findOrFail($id);
    }
}
