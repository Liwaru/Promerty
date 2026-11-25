<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgentListingRequest;
use App\Models\{PostMedia, PropertyCategory};
use App\Services\{AgentListingWorkflow, AgentMedia, ExternalListingVideo, FeedMedia};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, Request};

class ListingController extends Controller
{
    private const COLUMNS=['id','listing_code','listing_type','project_id','title','transaction_type','price','price_period','status','updated_at'];
    public function __construct(private readonly AgentListingWorkflow $workflow) {}
    public function index(Request $request): JsonResponse
    {
        $filters=$this->filters($request);
        $query=$request->user()->listings()->where('branch_id',$request->user()->branch_id);
        $activity=null;
        if($request->user()->hasPermission('view_own_analytics')){
            $ownedIds=(clone $query)->select('id');
            $postIds=\App\Models\Post::where('user_id',$request->user()->id)->whereIn('listing_id',$ownedIds)->select('id');
            $activity=['views'=>\App\Models\PostView::whereIn('post_id',$postIds)->count(),
                'likes'=>\App\Models\Like::whereIn('post_id',$postIds)->count(),
                'surveys'=>\App\Models\SurveyRequest::where('agent_id',$request->user()->id)->whereIn('listing_id',$ownedIds)->count()];
        }
        $counts=(clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total','status');
        $this->filterListings($query->getQuery(), $filters);
        match($filters['sort']??'newest'){'oldest'=>$query->orderBy('id'),'title'=>$query->orderBy('title')->orderBy('id'),default=>$query->latest('id')};
        $agentId = $request->user()->id;
        $page=$query->with('posts.media')->paginate(20,self::COLUMNS)->withQueryString()
            ->through(function ($listing) use ($agentId): array {
                $post = $listing->posts->sortBy('id')->first();
                $media = $post && $post->user_id === $agentId ? $post->media : collect();
                $cover = $media->first(fn ($item) => FeedMedia::canPresent($item, $item->media_type === 'video' ? 'video' : 'photo'));
                $video = $media->firstWhere('media_type', 'video');
                return [...$listing->only(self::COLUMNS), 'media_summary' => [
                    'photos' => $media->where('media_type', 'image')->count(),
                    'videos' => $media->where('media_type', 'video')->count(),
                    'video_status' => $video ? AgentMedia::optimizationState($video) : null,
                    'cover' => $cover ? [
                        'type' => $cover->media_type, 'url' => FeedMedia::url($cover->file_url),
                        'poster' => FeedMedia::url($cover->thumbnail_url),
                    ] : null,
                ]];
            })->toArray();
        return response()->json([...$page,'summary'=>['total'=>array_sum($counts->all()),'draft'=>(int)($counts['draft']??0),
            'published'=>(int)($counts['active']??0),'archived'=>(int)($counts['archived']??0)],
            'identity'=>['name'=>$request->user()->name,'branch'=>$request->user()->branch?->name,
                'verification'=>$request->user()->agentProfile?->verification_status],'activity'=>$activity]);
    }

    public function videos(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $agent = $request->user();
        abort_unless($agent->branch?->status === 'active', 403);
        $query = PostMedia::query()
            ->join('posts', 'posts.id', '=', 'post_media.post_id')
            ->join('listings', 'listings.id', '=', 'posts.listing_id')
            ->leftJoin('projects', 'projects.id', '=', 'listings.project_id')
            ->whereNull('posts.deleted_at')
            ->whereNull('listings.deleted_at')
            ->where('posts.user_id', $agent->id)
            ->where('listings.agent_id', $agent->id)
            ->where('listings.branch_id', $agent->branch_id)
            ->whereIn('listings.status', ['draft', 'active', 'archived'])
            ->where('post_media.media_type', 'video')
            ->where('post_media.file_url', 'like', 'agent-private/%.mp4');
        $this->filterListings($query, $filters, 'listings.');
        if (($filters['sort'] ?? null) === 'title') {
            $query->orderBy('listings.title');
        }
        $direction = ($filters['sort'] ?? null) === 'oldest' ? 'asc' : 'desc';
        $page = $query->orderBy('post_media.created_at', $direction)->orderBy('post_media.id', $direction)
            ->paginate(20, ['post_media.id', 'post_media.file_url', 'post_media.thumbnail_url', 'post_media.created_at',
                'listings.id as listing_id', 'listings.title', 'listings.price', 'listings.price_period',
                'listings.status', 'projects.name as project_name', 'posts.published_at'])->withQueryString();

        $page->setCollection($page->getCollection()->filter(fn (PostMedia $media) => AgentMedia::originalVideo($media) !== null)
            ->map(function (PostMedia $media): array {
                $publishedAt = $media->status === 'active' ? $media->getRawOriginal('published_at') : null;
                return [
                    'id' => $media->id, 'listing_id' => $media->listing_id, 'title' => $media->title,
                    'price' => $media->price, 'price_period' => $media->price_period,
                    'status' => $media->status, 'project_name' => $media->project_name,
                    'published_at' => $publishedAt ? \Illuminate\Support\Carbon::parse($publishedAt)->toISOString() : null,
                    'uploaded_at' => $media->created_at?->toISOString(),
                    'url' => FeedMedia::url($media->file_url),
                    'thumbnail' => FeedMedia::localPath($media->thumbnail_url) !== null ? FeedMedia::url($media->thumbnail_url) : null,
                ];
            })->values());

        return response()->json($page);
    }

    private function filters(Request $request): array
    {
        return $request->validate(['q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:draft,active,archived'], 'sort' => ['nullable', 'in:newest,oldest,title'],
            'project_id' => ['nullable', 'integer', 'min:1']]);
    }

    private function filterListings(Builder $query, array $filters, string $prefix = ''): void
    {
        if ($filters['q'] ?? null) $query->where($prefix.'title', 'like', '%'.$filters['q'].'%');
        if ($filters['status'] ?? null) $query->where($prefix.'status', $filters['status']);
        if ($filters['project_id'] ?? null) $query->where($prefix.'project_id', $filters['project_id']);
    }
    public function options(Request $request): JsonResponse
    {
        $assignments=app(\App\Services\ProjectAssignments::class);
        $available=$request->user()->hasPermission('create_listings') && $assignments->available();
        $projects=$available?$assignments->projects($request->user())->orderBy('name')->get(['id','name']):[];
        return response()->json(['categories'=>PropertyCategory::orderBy('name')->get(['id','name','slug']),
            'projects'=>$projects,'primary_available'=>$available,'can_manage_media'=>$request->user()->hasPermission('create_posts'),
            'limits'=>['image_max_kb'=>config('agent.image_max_kb'),'video_max_kb'=>config('agent.video_max_kb'),
                'video_max_bytes'=>min(750000000,(int)config('agent.video_max_bytes')),
                'resumable_video'=>(bool)config('agent.resumable_video'), 'photo_count'=>(int)config('agent.media_max_count'),
                'php_upload_max'=>ini_get('upload_max_filesize'),'php_post_max'=>ini_get('post_max_size')]]);
    }
    public function store(AgentListingRequest $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        abort_if($key !== null && !preg_match('~^[A-Za-z0-9._-]{1,128}$~D', $key), 422, 'Kunci draft tidak valid.');
        $listing=$this->workflow->save($request->user(),$request->validated(),requestKey:$key);
        $replayed = !$listing->wasRecentlyCreated;
        return response()->json(['data'=>$listing->only(self::COLUMNS), 'replayed'=>$replayed],$replayed ? 200 : 201);
    }
    public function update(AgentListingRequest $request,int $listing): JsonResponse
    {
        $item=$this->workflow->save($request->user(),$request->validated(),$listing);
        return response()->json(['data'=>$item->only(self::COLUMNS)]);
    }
    public function show(Request $request,int $listing): JsonResponse
    {
        $item=$this->workflow->owned($request->user(),$listing);$property=$item->secondaryProperty;$post=$item->posts()->oldest('id')->first();
        return response()->json(['data'=>[...$item->only([...self::COLUMNS,'category_id','description','bedrooms','bathrooms',
            'floors','land_area','building_area','furnishing','orientation','certificate','unit_type','additional_specs']),
            'location_label'=>$item->listing_type==='primary'?$item->project?->location?->label:$property?->location?->label,
            'address'=>$item->listing_type==='primary'?null:$property?->location?->address,
            'public_url'=>$item->status==='active' && $post?->status==='published'?'/posts/'.$post->id:null,
            'media'=>$post?->media->map(fn($m)=>['id'=>$m->id,'type'=>$m->media_type,'url'=>FeedMedia::url($m->file_url),
                'width'=>$m->width,'height'=>$m->height,'duration_seconds'=>$m->duration_seconds,
                'optimization'=>$m->media_type==='video' ? \App\Services\AgentMedia::optimizationState($m) : null,
                'external_video'=>$m->media_type === 'video' ? ExternalListingVideo::parse($m->file_url) : null])->all()??[]]]);
    }
    public function publish(Request $request,int $listing): JsonResponse
    {
        $this->workflow->transition($request->user(),$listing,'publish');
        return $this->show($request,$listing);
    }
    public function archive(Request $request,int $listing): JsonResponse
    {
        $this->workflow->transition($request->user(),$listing,'archive');
        return $this->show($request,$listing);
    }
}
