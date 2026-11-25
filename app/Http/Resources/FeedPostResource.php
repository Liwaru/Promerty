<?php

namespace App\Http\Resources;

use App\Models\{Listing, User};
use App\Services\ExternalListingVideo;
use App\Services\FeedMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedPostResource extends JsonResource
{
    private static function publicSpecs(mixed $specs): array
    {
        if (!is_array($specs)) return [];
        $safe=[];
        foreach (['carport'=>50,'garage'=>50,'apartment_floor'=>200,'balconies'=>50] as $key=>$max) {
            $value=$specs[$key]??null;
            if ((is_int($value)||(is_string($value)&&preg_match('/^\d{1,3}$/D',$value))) && (int)$value>=0 && (int)$value<=$max) $safe[$key]=(int)$value;
        }
        foreach (['tower', 'concept'] as $key) {
            $value=$specs[$key]??null;
            if (is_string($value)&&trim($value)!==''&&mb_strlen($value)<=100) $safe[$key]=$value;
        }
        return $safe;
    }
    public static function agent(User $user): array
    {
        // Development account bootstrap generates dev-local-* identifiers.
        $username = $user->username;
        $publicUsername = is_string($username) && !str_starts_with(strtolower($username), 'dev-local-')
            && preg_match('/^[A-Za-z0-9_.]{1,50}$/D', $username) ? $username : null;

        return [
            'id' => $user->id, 'name' => $user->name, 'username' => $publicUsername,
            'photo' => FeedMedia::url($user->photo), 'bio' => $user->bio,
            'verified' => $user->hasRole('agent') && (bool) $user->agentProfile?->isVerified(),
            'branch' => $user->branch?->name, 'agency' => $user->branch?->agency?->name,
        ];
    }

    public function toArray(Request $request): array
    {
        $listing = $this->listing;
        $primary = $listing->listing_type === 'primary';
        $location = $primary ? $listing->project?->location : $listing->secondaryProperty?->location;
        $exact = $primary && $location?->visibility === 'exact';
        $agent = $listing->agent;
        $photos = $this->content_type === 'photo';
        $media = $this->media->filter(fn ($item) => FeedMedia::canPresent($item, $this->content_type));
        return [
            'id' => $this->id, 'caption' => $this->caption, 'content_type' => $this->content_type,
            'published_at' => $this->published_at?->toIso8601String(),
            'url' => url('/posts/'.$this->id),
            'author' => self::agent($this->user), 'agent' => self::agent($agent),
            'listing' => [
                'id' => $listing->id, 'title' => $listing->title, 'description' => $listing->description,
                'type' => $listing->listing_type, 'transaction_type' => $listing->transaction_type,
                'project_name' => $primary ? $listing->project?->name : null,
                'price' => $listing->price, 'price_period' => $listing->price_period, 'currency' => 'IDR',
                'category' => $listing->category?->name, 'bedrooms' => $listing->bedrooms,
                'bathrooms' => $listing->bathrooms, 'land_area' => $listing->land_area, 'building_area' => $listing->building_area,
                'floors'=>$listing->floors, 'unit_type'=>$listing->unit_type, 'certificate'=>$listing->certificate,
                'furnishing'=>$listing->furnishing, 'electricity'=>$listing->electricity, 'negotiable'=>$listing->negotiable,
                'orientation'=>in_array($listing->orientation,Listing::PUBLIC_ORIENTATIONS,true)?$listing->orientation:null,
                'additional_specs'=>self::publicSpecs($listing->additional_specs),
                'facilities'=>$primary ? array_values(array_filter($listing->project?->facilities ?? [],'is_string')) : [],
                // Explicit allowlist: never serialize raw Location or its private fields.
                'location' => $location ? [
                    'label' => $location->label, 'district' => $location->district, 'city' => $location->city,
                    'province' => $location->province, 'visibility' => $exact ? 'exact' : 'approximate',
                    'latitude' => $exact ? $location->latitude : $location->public_latitude,
                    'longitude' => $exact ? $location->longitude : $location->public_longitude,
                    'radius_m' => $exact ? null : $location->public_radius_m,
                ] : null,
            ],
            'media' => $media->take($photos ? (int) config('agent.media_max_count') : 1)->map(fn ($m) => [
                'id' => $m->id, 'type' => $m->media_type, 'url' => FeedMedia::url($m->file_url),
                'poster' => FeedMedia::url($m->thumbnail_url), 'width' => $m->width, 'height' => $m->height,
                'orientation' => !$m->width || !$m->height ? 'unknown' : ($m->width > $m->height ? 'landscape' : ($m->width === $m->height ? 'square' : 'portrait')),
                'aspect_ratio' => $m->width && $m->height ? round($m->width / $m->height, 4) : null,
                'duration_seconds' => $m->duration_seconds,
                'downloadable' => FeedMedia::isLocalMp4($m) && $listing->permitsVideoDownload(),
                'external_video' => $m->media_type === 'video' ? ExternalListingVideo::parse($m->file_url) : null,
            ])->values()->all(),
            'counts' => ['likes' => $this->likes_count, 'comments' => $this->comments_count, 'shares' => $this->shares_count,
                'saves' => $request->user()?->id === $this->user_id ? $this->saves_count : null,
                'views' => $this->views_count, 'downloads' => $this->download_sessions_count],
            'viewer' => ['liked' => (bool) $this->viewer_liked, 'saved' => (bool) $this->viewer_saved,
                'following' => in_array($agent->id, $request->attributes->get('feed_following', []), true)],
        ];
    }
}
