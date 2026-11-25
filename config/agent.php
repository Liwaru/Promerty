<?php

return [
    'image_max_kb' => 20480,
    'video_max_kb' => 102400,
    'video_max_bytes' => max(1, min(750000000, (int) env('AGENT_VIDEO_MAX_BYTES', 750000000))),
    'resumable_video' => env('AGENT_RESUMABLE_VIDEO', false),
    'video_optimization' => env('AGENT_VIDEO_OPTIMIZATION', false),
    'video_chunk_bytes' => 4194304,
    'video_upload_slots' => 3,
    'video_disk_reserve_bytes' => 1073741824,
    'media_max_count' => 30,
];
