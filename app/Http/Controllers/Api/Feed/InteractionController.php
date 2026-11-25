<?php

namespace App\Http\Controllers\Api\Feed;

use App\Http\Controllers\Controller;
use App\Models\{Comment, Follow, Like, Save, Share, User};
use App\Services\FeedService;
use App\Support\RoleCatalog;
use Illuminate\Http\Request;

class InteractionController extends Controller
{
    public function __construct(private FeedService $feed) {}

    public function like(Request $request, int $post) { return $this->reaction($request, $post, Like::class); }
    public function save(Request $request, int $post) { return $this->reaction($request, $post, Save::class); }

    private function reaction(Request $request, int $post, string $model)
    {
        $this->feed->visible()->findOrFail($post);
        $key = ['post_id' => $post, 'user_id' => $request->user()->id];
        if ($request->isMethod('delete')) $model::where($key)->delete();
        else $model::firstOrCreate($key);
        return response()->json(['active' => !$request->isMethod('delete'), 'count' => $model::where('post_id', $post)->count()]);
    }

    public function follow(Request $request, int $agent)
    {
        abort_if(in_array($request->user()->role?->name, RoleCatalog::PERSONAL_INTERACTION_ROLES, true), 403);
        abort_if($agent === (int) $request->user()->id, 422, 'Anda tidak dapat mengikuti diri sendiri.');
        User::where('status','active')->whereHas('role', fn ($q) => $q->where('name','agent'))->findOrFail($agent);
        $key = ['follower_id' => $request->user()->id, 'following_id' => $agent];
        if ($request->isMethod('delete')) Follow::where($key)->delete(); else Follow::firstOrCreate($key);
        return response()->json(['active' => !$request->isMethod('delete')]);
    }

    public function comments(Request $request, int $post)
    {
        $this->feed->visible()->findOrFail($post);
        $data = $request->validate(['parent_id'=>['nullable','integer','min:1']]);
        $query = FeedService::activeComments(Comment::query())->where('post_id',$post)->with('user:id,name,username,photo');
        if (!empty($data['parent_id'])) $query->where('parent_id', $data['parent_id']); else $query->whereNull('parent_id');
        $page = $query->orderByDesc('id')->simplePaginate(20);
        return response()->json(['data' => $page->map(fn ($c) => $this->commentData($c)), 'has_more' => $page->hasMorePages()]);
    }

    public function comment(Request $request, int $post)
    {
        $this->feed->visible()->findOrFail($post);
        $data = $request->validate(['content'=>['required','string','max:2000'], 'parent_id'=>['nullable','integer','min:1']]);
        if (!empty($data['parent_id'])) {
            // One reply level keeps hidden/deleted parent moderation unambiguous.
            Comment::where('post_id',$post)->where('moderation_status','active')->whereNull('parent_id')->findOrFail($data['parent_id']);
        }
        $comment = Comment::create([...$data, 'post_id'=>$post, 'user_id'=>$request->user()->id, 'moderation_status'=>'active']);
        $comment->setRelation('user', $request->user());
        return response()->json(['data'=>$this->commentData($comment)],201);
    }

    private function commentData(Comment $comment): array
    {
        return ['id'=>$comment->id,'parent_id'=>$comment->parent_id,'content'=>$comment->content,'created_at'=>$comment->created_at?->toIso8601String(),
            'user'=>['id'=>$comment->user?->id,'name'=>$comment->user?->name ?? 'Pengguna tidak tersedia','username'=>$comment->user?->username,'photo'=>\App\Services\FeedMedia::url($comment->user?->photo)]];
    }

    public function share(Request $request, int $post)
    {
        $this->feed->visible()->findOrFail($post);
        $data = $request->validate(['platform'=>['required','in:copy,web_share']]);
        Share::create(['post_id'=>$post, 'user_id'=>$request->user()?->id, 'session_id'=>$this->identity($request), ...$data]);
        return response()->json(['message'=>'Link dibagikan.'],201);
    }

    public function identity(Request $request): string
    {
        return hash_hmac('sha256', $request->ip().'|'.$request->userAgent(), (string) config('app.key'));
    }
}
