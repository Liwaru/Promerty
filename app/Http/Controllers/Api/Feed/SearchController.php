<?php

namespace App\Http\Controllers\Api\Feed;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\FeedPostResource;
use App\Models\Follow;
use App\Services\SearchService;

class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, SearchService $search)
    {
        $result=$search->search($request->validated(),$request->user());
        $ids=$result['kind']==='agent'?$result['items']->pluck('id'):$result['items']->pluck('listing.agent_id');
        $following=$request->user()?Follow::where('follower_id',$request->user()->id)->whereIn('following_id',$ids)->pluck('following_id')->map(fn($id)=>(int)$id)->all():[];
        $request->attributes->set('feed_following',$following);
        $data=$result['kind']==='agent'
            ?$result['items']->map(fn($u)=>[...FeedPostResource::agent($u),'following'=>in_array($u->id,$following,true),'url'=>url('/agents/'.$u->id)])->all()
            :FeedPostResource::collection($result['items'])->resolve($request);
        return response()->json(['kind'=>$result['kind'],'data'=>$data,'interpreted'=>$result['interpreted'],'next_page'=>$result['next_page']]);
    }
}
