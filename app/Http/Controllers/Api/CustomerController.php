<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\{CustomerMessageRequest, CustomerProfileRequest};
use App\Http\Resources\FeedPostResource;
use App\Models\{Comment, Conversation, Follow, Like, Notification, Save, SurveyRequest};
use App\Services\{FeedMedia, FeedService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate, Storage};

class CustomerController extends Controller
{
    public function profile(Request $request)
    {
        $u = $request->user();
        return response()->json(['data'=>['name'=>$u->name,'username'=>$u->username,'bio'=>$u->bio,
            'photo'=>FeedMedia::url($u->photo),'role'=>$u->role?->name,'phone_verified'=>(bool) $u->phone_verified_at],
            'availability'=>['whatsapp'=>false,'survey_cancellation'=>false,'google_login'=>false,'production_sms'=>false]]);
    }

    public function updateProfile(CustomerProfileRequest $request)
    {
        $user = $request->user(); $old = $user->photo; $path = null;
        $data = $request->safe()->only(['name','username','bio']);
        if ($request->hasFile('photo')) $data['photo'] = $path = $request->file('photo')->store('customer-avatars','public');
        if ($request->hasFile('photo') && !$path) abort(503,'Foto belum dapat disimpan. Silakan coba lagi.');
        try { $user->update($data); }
        catch (\Throwable $e) { if ($path) Storage::disk('public')->delete($path); throw $e; }
        // Only delete files managed by this feature, after a successful database update.
        if ($path && $old && str_starts_with($old,'customer-avatars/')) Storage::disk('public')->delete($old);
        return $this->profile($request);
    }

    private function page($query, callable $map)
    {
        $page = $query->simplePaginate(20);
        return response()->json(['data'=>$page->getCollection()->map($map)->values(),'has_more'=>$page->hasMorePages()]);
    }

    public function following(Request $request)
    {
        return $this->page(Follow::where('follower_id',$request->user()->id)
            ->whereHas('following',fn($q)=>$q->where('status','active')->whereHas('role',fn($r)=>$r->where('name','agent')))
            ->with('following.role','following.agentProfile','following.branch.agency')->orderByDesc('id'),
            fn($f)=>FeedPostResource::agent($f->following));
    }

    public function surveys(Request $request)
    {
        return $this->page(SurveyRequest::where('customer_id',$request->user()->id)->with('listing:id,title','agent:id,name')->orderByDesc('id'),
            fn($s)=>['id'=>$s->id,'status'=>$s->status,'requested_at'=>$s->requested_at?->toIso8601String(),
                'proposed_at'=>$s->proposed_at?->toIso8601String(),'note'=>$s->note,'listing'=>$s->listing?->only(['id','title']),
                'agent'=>$s->agent?->only(['id','name']),'can_cancel'=>false]);
    }

    public function incomingSurveys(Request $request)
    {
        $agent = $request->user();
        abort_unless($agent->branch?->status === 'active', 403);
        return $this->page(SurveyRequest::where('agent_id', $agent->id)
            ->whereHas('listing', fn ($q) => $q->where('agent_id', $agent->id)->where('branch_id', $agent->branch_id))
            ->with('listing:id,title')->orderByDesc('id'),
            fn ($s) => ['id'=>$s->id,'status'=>$s->status,'requested_at'=>$s->requested_at?->toIso8601String(),
                'proposed_at'=>$s->proposed_at?->toIso8601String(),'note'=>$s->note,'listing'=>$s->listing?->only(['id','title'])]);
    }

    private function inboxQuery(Request $request)
    {
        return Conversation::where('type','direct')->where('status','active')
            ->whereHas('members',fn($q)=>$q->where('user_id',$request->user()->id))->has('members','=',2);
    }

    public function inbox(Request $request)
    {
        $query = $this->inboxQuery($request);
        if ($request->user()->hasRole('agent')) {
            $scope = $request->validate(['scope'=>'nullable|in:started,incoming'])['scope'] ?? 'started';
            $query->where('created_by', $scope === 'started' ? '=' : '!=', $request->user()->id);
        }
        return $this->page($query->with(['members.user:id,name,photo',
            'messages'=>fn($q)=>$q->whereNull('unsent_at')->latest('id')->limit(1)])
            ->orderByDesc('last_message_at')->orderByDesc('id'), function($c) use($request) {
                $other = $c->members->first(fn($m)=>(int)$m->user_id !== (int)$request->user()->id)?->user;
                return ['id'=>$c->id,'agent'=>$other?->only(['id','name']),
                    'last_message'=>$c->messages->first()?->body,'last_message_at'=>$c->last_message_at?->toIso8601String()];
            });
    }

    public function messages(Request $request, int $conversation)
    {
        $thread = $this->inboxQuery($request)->findOrFail($conversation);
        $page = $this->page($thread->messages()->whereNull('unsent_at')->with('senderMember:id,user_id')->orderByDesc('id'),
            fn($m)=>['id'=>$m->id,'body'=>$m->body,'mine'=>(int)$m->senderMember?->user_id === (int)$request->user()->id,'created_at'=>$m->created_at?->toIso8601String()]);
        if (!$request->user()->hasRole('agent')) return $page;
        return response()->json($page->getData(true) + ['can_reply' => $this->hasActiveAgentPeer($request, $thread)]);
    }

    public function send(CustomerMessageRequest $request, int $conversation)
    {
        $thread = $this->inboxQuery($request)->findOrFail($conversation);
        if (!$request->user()->phone_verified_at) return response()->json(['message'=>'Verifikasi nomor HP diperlukan.','code'=>'PHONE_VERIFICATION_REQUIRED'],403);
        abort_unless($this->hasActiveAgentPeer($request, $thread),422,'Agent tidak tersedia.');
        DB::transaction(function() use($thread,$request) {
            $thread->lockForUpdate()->findOrFail($thread->id);
            $member = $thread->members()->where('user_id',$request->user()->id)->firstOrFail();
            $thread->messages()->create(['sender_member_id'=>$member->id,'message_type'=>'text','body'=>$request->validated('body')]);
            $thread->update(['last_message_at'=>now()]);
        });
        return response()->json(['message'=>'Pesan terkirim.'],201);
    }

    private function hasActiveAgentPeer(Request $request, Conversation $thread): bool
    {
        return $thread->members()->where('user_id','!=',$request->user()->id)->whereHas('user',
            fn($q)=>$q->where('status','active')->whereHas('role',fn($r)=>$r->where('name','agent')))->exists();
    }

    public function notifications(Request $request)
    {
        $request->validate(['unread'=>'sometimes|boolean']);
        return $this->page(Notification::where('user_id',$request->user()->id)
            ->when($request->boolean('unread'),fn($query)=>$query->whereNull('read_at'))->latest('id'),
            fn($n)=>$n->only(['id','type','title','message','read_at','created_at']));
    }

    public function readNotification(Request $request, int $notification)
    {
        $item = Notification::where('user_id',$request->user()->id)->findOrFail($notification);
        if (!$item->read_at) $item->update(['read_at'=>now()]);
        return response()->json(['message'=>'Notifikasi ditandai dibaca.']);
    }

    public function activity(Request $request)
    {
        $type = $request->validate(['type'=>['required','in:likes,comments,saves']])['type'];
        $model = ['likes'=>Like::class,'comments'=>Comment::class,'saves'=>Save::class][$type];
        $query = $model::where('user_id',$request->user()->id)
            ->whereIn('post_id',app(FeedService::class)->visible()->select('posts.id'))->with('post.listing:id,title')->latest('id');
        if ($type === 'comments') FeedService::activeComments($query);
        return $this->page($query,fn($a)=>['id'=>$a->id,'post_id'=>$a->post_id,'title'=>$a->post?->listing?->title,
            'content'=>$type==='comments'?$a->content:null,'created_at'=>$a->created_at?->toIso8601String()]);
    }

    public function changeComment(Request $request, int $comment)
    {
        $item = Comment::findOrFail($comment);
        Gate::authorize($request->isMethod('delete')?'delete':'update',$item);
        app(FeedService::class)->visible()->findOrFail($item->post_id);
        if ($request->isMethod('delete')) $item->delete();
        else $item->update($request->validate(['content'=>['required','string','max:2000']]));
        return response()->json(['message'=>$request->isMethod('delete')?'Komentar dihapus.':'Komentar diperbarui.']);
    }
}
