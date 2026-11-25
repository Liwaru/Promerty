<?php

namespace App\Http\Controllers\Api\Feed;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeedPostResource;
use App\Models\{AppSetting, Follow, PropertyCategory, User};
use App\Services\FeedService;
use App\Services\FeedMedia;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedController extends Controller
{
    public function __construct(private FeedService $feed) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'saved' => ['nullable','boolean'],
            'q' => ['nullable','string','max:120'], 'type' => ['nullable',Rule::in(['primary','secondary'])],
            'transaction' => ['nullable',Rule::in(['sale','rent'])], 'category' => ['nullable','integer','min:1'],
            'agent' => ['nullable','integer','min:1'], 'min_price' => ['nullable','numeric','min:0'],
            'max_price' => ['nullable','numeric','min:0'], 'cursor' => ['nullable','string','max:2048'],
        ]);
        if (isset($filters['min_price'], $filters['max_price']) && $filters['min_price'] > $filters['max_price']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['max_price'=>'Harga maksimum harus sama atau lebih besar dari harga minimum.']);
        }
        $query = $this->feed->withPresentationCandidates($this->feed->query($request->user()));
        if (!empty($filters['saved'])) {
            abort_unless($request->user(), 401);
            abort_unless($request->user()->hasPermission('interact_posts'), 403);
            $query->whereHas('saves', fn ($q) => $q->where('user_id', $request->user()->id));
        }
        if ($request->routeIs('feed.following')) {
            $query->whereHas('listing',fn($q)=>$q->whereIn('agent_id', Follow::where('follower_id', $request->user()->id)->select('following_id')));
        }
        if (!empty($filters['agent'])) $query->whereHas('listing', fn ($q) => $q->where('agent_id', $filters['agent']));
        $query->whereHas('listing', function ($q) use ($filters) {
            foreach (['type'=>'listing_type','transaction'=>'transaction_type','category'=>'category_id'] as $key=>$column) {
                if (!empty($filters[$key])) $q->where($column, $filters[$key]);
            }
            if (isset($filters['min_price'])) $q->where('price', '>=', $filters['min_price']);
            if (isset($filters['max_price'])) $q->where('price', '<=', $filters['max_price']);
            if (!empty($filters['q'])) {
                $term = '%'.str_replace(['!','%','_'], ['!!','!%','!_'], $filters['q']).'%';
                $q->where(fn ($q) => $q->whereRaw("title LIKE ? ESCAPE '!'", [$term])
                    ->orWhereHas('agent', fn ($a) => $a->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("username LIKE ? ESCAPE '!'", [$term]))
                    ->orWhereHas('project.location', fn ($l) => $l->whereRaw("label LIKE ? ESCAPE '!'", [$term])->orWhereRaw("city LIKE ? ESCAPE '!'", [$term]))
                    ->orWhereHas('secondaryProperty.location', fn ($l) => $l->whereRaw("label LIKE ? ESCAPE '!'", [$term])->orWhereRaw("city LIKE ? ESCAPE '!'", [$term])));
            }
        });
        // Stable recency cursor. Engagement reranks only this bounded recent batch.
        $page = $query->orderByDesc('published_at')->orderByDesc('id')->cursorPaginate(8);
        $next = $page->nextCursor()?->encode();
        $posts = $page->getCollection()->sortByDesc(fn ($p) => $p->published_at->timestamp + min(21600, log(1 + $p->likes_count + 2 * $p->comments_count + $p->shares_count) * 1800))->values();
        $this->following($request, $posts->pluck('listing.agent_id')->all());
        $cards = array_values(array_filter(FeedPostResource::collection($posts)->resolve($request), fn (array $card) => $card['media'] !== []));
        return response()->json(['data' => $cards, 'next_cursor' => $next]);
    }

    public function show(Request $request, int $post)
    {
        $item = $this->feed->post($post, $request->user());
        abort_unless($item->media->contains(fn ($media) => FeedMedia::canPresent($media, $item->content_type)), 404);
        $this->following($request, [$item->listing->agent_id]);
        return new FeedPostResource($item);
    }

    private function following(Request $request, array $ids): void
    {
        $request->attributes->set('feed_following', $request->user() ? Follow::where('follower_id', $request->user()->id)->whereIn('following_id', $ids)->pluck('following_id')->map(fn ($id) => (int) $id)->all() : []);
    }

    public function agent(Request $request, int $agent)
    {
        $user = User::with('role','agentProfile','branch.agency')->where('status','active')->whereHas('role', fn ($q) => $q->where('name','agent'))->findOrFail($agent);
        return response()->json(['data' => [...FeedPostResource::agent($user), 'following'=>$request->user() ? Follow::where('follower_id',$request->user()->id)->where('following_id',$user->id)->exists() : false]]);
    }

    public function config()
    {
        return response()->json([
            'categories' => PropertyCategory::orderBy('name')->get(['id','name']),
            'downloads' => AppSetting::whereIn('setting_key', ['allow_photo_download','allow_video_download'])->get()->mapWithKeys(fn ($s) => [$s->setting_key => $s->typedValue() === true]),
        ]);
    }
}
