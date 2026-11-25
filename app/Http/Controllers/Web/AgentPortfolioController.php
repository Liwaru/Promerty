<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeedPostResource;
use App\Models\{Follow, Like, User};
use App\Services\{FeedMedia, FeedService};
use App\Support\RoleCatalog;
use Illuminate\Http\Request;

final class AgentPortfolioController extends Controller
{
    public function __invoke(Request $request, int $agent, FeedService $feed)
    {
        $user = User::with('role:id,name', 'agentProfile:id,user_id,verification_status', 'branch:id,name,agency_id', 'branch.agency:id,name')
            ->where('status', 'active')->whereHas('role', fn ($query) => $query->where('name', 'agent'))
            ->findOrFail($agent, ['id', 'name', 'username', 'photo', 'bio', 'role_id', 'branch_id']);
        $profile = FeedPostResource::agent($user);
        $viewer = $request->user('web');
        $ownProfile = $viewer?->id === $user->id;
        $tabs = ['all' => 'Semua Listing', 'video' => 'Video', 'photo' => 'Foto'];
        $requestedTab = $request->query('media');
        $tab = is_string($requestedTab) && isset($tabs[$requestedTab]) ? $requestedTab : 'all';

        $publicPosts = $feed->withPresentationCandidates($feed->visible())->where('user_id', $user->id)
            ->whereHas('listing', fn ($query) => $query->where('agent_id', $user->id));
        $publicListings = $user->listings()->whereIn('id', (clone $publicPosts)->select('listing_id'));
        $stats = [
            'Listing terpublikasi' => (clone $publicListings)->count(),
            'Pengikut' => $user->followers()->where('users.status', 'active')->count(),
            'Suka' => Like::whereIn('post_id', (clone $publicPosts)->select('id'))->count(),
        ];
        $postsForTab = (clone $publicPosts)->when($tab !== 'all', fn ($query) => $query->where('content_type', $tab));
        $postIds = (clone $postsForTab)->select('id');
        $page = $publicListings->whereIn('id', (clone $postsForTab)->select('listing_id'))
            ->with(['posts' => fn ($query) => $query->whereIn('posts.id', $postIds)
                ->with('media')->latest('published_at')->orderByDesc('id')])
            ->latest('id')->paginate(18, ['id', 'title'])
            ->appends($tab === 'all' ? [] : ['media' => $tab]);
        $cards = $page->getCollection()->map(function ($listing) {
            foreach ($listing->posts as $post) {
                $media = $post->media->first(fn ($item) => FeedMedia::canPresent($item, $post->content_type));
                if (!$media) continue;
                $source = FeedMedia::url($media->file_url);
                $poster = FeedMedia::url($media->thumbnail_url);

                return [
                    'post' => $post->id, 'title' => $listing->title, 'type' => $post->content_type,
                    'photo' => $post->content_type === 'photo' ? $source : $poster,
                    'frame' => $post->content_type === 'video' && !$poster && str_starts_with($source, '/') ? $source : null,
                ];
            }
            return null;
        })->filter();

        $canFollow = !$ownProfile && $viewer?->isActive() && $viewer->hasPermission('interact_posts')
            && !in_array($viewer->role?->name, RoleCatalog::PERSONAL_INTERACTION_ROLES, true);
        $following = $canFollow && Follow::where('follower_id', $viewer->id)->where('following_id', $user->id)->exists();
        return view('agent.portfolio', compact('profile', 'ownProfile', 'tabs', 'tab', 'stats', 'page', 'cards', 'canFollow', 'following', 'viewer'));
    }
}
