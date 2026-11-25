<?php

namespace App\Jobs;

use App\Models\PostMedia;
use App\Services\{AgentMedia, LightMp4Optimization};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class OptimizeAgentVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public readonly int $mediaId)
    {
        $this->onQueue('media');
    }

    public function handle(LightMp4Optimization $optimizer): void
    {
        if (!config('agent.video_optimization')) return;
        $media = PostMedia::find($this->mediaId);
        $file = $media ? AgentMedia::originalVideo($media) : null;
        if ($file === null) return;
        try {
            AgentMedia::optimizationState($media,'optimizing');
            $result = $optimizer->trim($file, fn () => PostMedia::whereKey($this->mediaId)->exists());
            AgentMedia::optimizationState($media,$result['outcome']);
            Log::info('agent.video_optimization', ['media_id'=>$this->mediaId, ...$result]);
        } catch (\Throwable) {
            AgentMedia::optimizationState($media,'failed');
            Log::warning('agent.video_optimization_failed', ['media_id'=>$this->mediaId]);
        }
    }
}
