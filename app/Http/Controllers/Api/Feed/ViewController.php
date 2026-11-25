<?php

namespace App\Http\Controllers\Api\Feed;

use App\Http\Controllers\Controller;
use App\Models\PostView;
use App\Services\FeedService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ViewController extends Controller
{
    public function __invoke(Request $request, int $post)
    {
        app(FeedService::class)->visible()->findOrFail($post);
        $data = $request->validate(['watch_duration_seconds'=>['required','numeric','min:3','max:14400'], 'completed'=>['sometimes','boolean']]);
        $identity = app(InteractionController::class)->identity($request);
        $key = 'feed:view:'.$post.':'.($request->user()?->id ?? $identity);
        if (Cache::add($key,true,now()->addMinutes(30))) {
            try {
                PostView::create([...$data,'post_id'=>$post,'user_id'=>$request->user()?->id,'session_id'=>$identity,'viewed_at'=>now()]);
            } catch (\Throwable $error) { Cache::forget($key); throw $error; }
        }
        return response()->noContent();
    }
}
