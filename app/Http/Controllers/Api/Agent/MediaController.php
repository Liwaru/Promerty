<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\PostMedia;
use App\Services\{AgentListingWorkflow, AgentMedia, FeedService};
use Illuminate\Http\Request;

class MediaController extends Controller
{
    public function reorder(Request $request,int $listing)
    {
        $data=$request->validate(['media_ids'=>['required','array','min:1','max:'.config('agent.media_max_count')],'media_ids.*'=>['required','integer','distinct']]);
        \Illuminate\Support\Facades\DB::transaction(function()use($request,$listing,$data){
            $workflow=app(AgentListingWorkflow::class);$item=$workflow->owned($request->user(),$listing,true);$workflow->editable($item);
            $post=$workflow->post($item);$existing=$post->media()->pluck('id')->map(fn($id)=>(int)$id)->all();
            $ids=array_map('intval',$data['media_ids']);$sorted=$ids;sort($sorted);sort($existing);
            abort_unless($sorted===$existing,422,'Pilih seluruh media listing ini tanpa media lain.');
            // Move into a disjoint temporary range before swapping unique positions.
            // Listing lock serializes upload/delete/reorder; rollback restores every position.
            $offset=(int)$post->media()->max('sort_order')+1;
            abort_if($offset+count($ids)>65535,409,'Urutan media perlu diperiksa sebelum diubah.');
            foreach($ids as $order=>$id)$post->media()->whereKey($id)->update(['sort_order'=>$offset+$order]);
            foreach($ids as $order=>$id)$post->media()->whereKey($id)->update(['sort_order'=>$order+1]);
            app(\App\Services\SecurityAudit::class)->record('listing.media_reordered',$request->user(),['listing_id'=>$listing]);
        });
        return response()->json(['reordered'=>true]);
    }
    public function store(Request $request,int $listing)
    {
        $request->validate(['media'=>['required','file','max:'.max(config('agent.image_max_kb'),config('agent.video_max_kb'))]]);
        $item=app(AgentMedia::class)->upload($request->user(),$listing,$request->file('media'));
        return response()->json(['data'=>['id'=>$item->id]],201);
    }
    public function link(Request $request, int $listing)
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:512'], 'provider'=>['sometimes','in:tiktok,instagram']]);
        $item = app(AgentMedia::class)->attachExternal($request->user(), $listing, $data['url'], $data['provider'] ?? null);
        return response()->json(['data' => ['id' => $item->id]], $item->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request,int $listing,int $media)
    {
        $removed=app(AgentMedia::class)->remove($request->user(),$listing,$media);
        return response()->json(['removed'=>true,'file_removed'=>$removed]);
    }
    public function show(Request $request,string $key)
    {
        $media=PostMedia::where('file_url','agent-private/'.$key)->firstOrFail();
        if (!app(FeedService::class)->visible()->whereKey($media->post_id)->exists()) {
            $post=$media->post;abort_unless($request->user() && $post && $request->user()->id===$post->user_id,404);
            app(AgentListingWorkflow::class)->owned($request->user(),$post->listing_id);
        }
        return AgentMedia::response($media);
    }
}
