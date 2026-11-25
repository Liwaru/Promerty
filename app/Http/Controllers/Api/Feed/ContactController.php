<?php

namespace App\Http\Controllers\Api\Feed;

use App\Http\Controllers\Controller;
use App\Models\{Conversation, Listing, Message, SurveyRequest, User};
use App\Services\FeedService;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    private function phone(Request $request): void
    {
        if ($request->user()->hasRole('customer') && !$request->user()->phone_verified_at) {
            throw new HttpResponseException(response()->json(['message'=>'Verifikasi nomor HP untuk melanjutkan.','code'=>'PHONE_VERIFICATION_REQUIRED'],403));
        }
    }

    private function listing(Request $request, int $id): Listing
    {
        $this->phone($request);
        $post = app(FeedService::class)->visible()->where('listing_id',$id)->firstOrFail();
        $listing = $post->listing;
        abort_if((int) $listing->agent_id === (int) $request->user()->id,422,'Tidak dapat menghubungi atau meminta survei listing sendiri.');
        return $listing;
    }

    public function survey(Request $request, int $listing)
    {
        $target = $this->listing($request,$listing);
        $data = $request->validate(['requested_at'=>['required','date','after:now'], 'note'=>['nullable','string','max:2000']]);
        $survey = DB::transaction(function() use($request,$target,$data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            return SurveyRequest::firstOrCreate(['listing_id'=>$target->id,'customer_id'=>$request->user()->id,
                'requested_at'=>\Carbon\Carbon::parse($data['requested_at'])->utc()->format('Y-m-d H:i:s'),
                'note'=>$data['note']??null,'status'=>'pending'],['agent_id'=>$target->agent_id]);
        });
        return response()->json(['data'=>['id'=>$survey->id,'status'=>$survey->status,'requested_at'=>$survey->requested_at->toIso8601String()]],$survey->wasRecentlyCreated?201:200);
    }

    public function message(Request $request, int $listing)
    {
        $target = $this->listing($request,$listing);
        $data = $request->validate(['body'=>['required','string','max:4000']]);
        $conversation = DB::transaction(function () use ($request,$target,$data) {
            // Serialize creation per sender, preventing duplicate direct conversations on concurrent submits.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $conversation = $this->conversation($request,$target)->first();
            if (!$conversation) {
                $conversation = Conversation::create(['type'=>'direct','created_by'=>$request->user()->id,'status'=>'active']);
                $conversation->members()->create(['user_id'=>$request->user()->id,'member_role'=>$request->user()->hasRole('agent') ? 'agent' : 'customer','joined_at'=>now()]);
                $conversation->members()->create(['user_id'=>$target->agent_id,'member_role'=>'agent','joined_at'=>now()]);
            }
            $member = $conversation->members()->where('user_id',$request->user()->id)->firstOrFail();
            $conversation->messages()->create(['sender_member_id'=>$member->id,'message_type'=>'text','body'=>$data['body'],'listing_id'=>$target->id]);
            $conversation->update(['last_message_at'=>now()]);
            return $conversation;
        });
        return response()->json(['data'=>['conversation_id'=>$conversation->id], 'message'=>'Pesan terkirim kepada Agent.'],201);
    }

    private function conversation(Request $request, Listing $listing)
    {
        return Conversation::where('type','direct')->where('status','active')
            ->whereHas('members',fn ($q)=>$q->where('user_id',$request->user()->id))
            ->whereHas('members',fn ($q)=>$q->where('user_id',$listing->agent_id))
            ->has('members','=',2);
    }

    public function messages(Request $request, int $listing)
    {
        $target = $this->listing($request,$listing);
        $conversation = $this->conversation($request,$target)->first();
        if (!$conversation) return response()->json(['data'=>[], 'has_more'=>false]);
        $page = $conversation->messages()->whereNull('unsent_at')->with('senderMember:id,user_id')->orderByDesc('id')->simplePaginate(30);
        return response()->json(['data'=>$page->map(fn ($m)=>['id'=>$m->id,'body'=>$m->body,'mine'=>$m->senderMember?->user_id === $request->user()->id,'created_at'=>$m->created_at?->toIso8601String()]), 'has_more'=>$page->hasMorePages()]);
    }
}
