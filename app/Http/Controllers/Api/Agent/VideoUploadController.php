<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Services\AgentVideoUpload;
use Illuminate\Http\Request;

final class VideoUploadController extends Controller
{
    public function begin(Request $request, int $listing, AgentVideoUpload $upload)
    {
        $data = $request->validate(['size'=>'required|integer|min:1|max:750000000', 'sha256'=>'required|regex:/^[a-f0-9]{64}$/D']);
        return response()->json($upload->begin($request->user(), $listing, (int)$data['size'], $data['sha256']));
    }

    public function append(Request $request, int $listing, string $id, AgentVideoUpload $upload)
    {
        $data = $request->validate(['offset'=>'required|integer|min:0|max:750000000', 'sha256'=>'required|regex:/^[a-f0-9]{64}$/D',
            'chunk'=>'required|file|max:'.intdiv(config('agent.video_chunk_bytes'), 1024)]);
        return response()->json($upload->append($request->user(), $listing, $id, (int)$data['offset'], $data['sha256'], $data['chunk']));
    }

    public function finish(Request $request, int $listing, string $id, AgentVideoUpload $upload)
    {
        return response()->json($upload->finish($request->user(), $listing, $id));
    }

    public function cancel(Request $request, int $listing, string $id, AgentVideoUpload $upload)
    {
        $upload->cancel($request->user(), $listing, $id);
        return response()->noContent();
    }
}
