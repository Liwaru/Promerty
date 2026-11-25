<?php

namespace App\Http\Controllers\Api\Feed;

use App\Http\Controllers\Controller;
use App\Models\{AppSetting, DownloadItem, DownloadSession, Post, PostMedia};
use App\Services\{AgentMedia, FeedMedia, FeedService};
use App\Support\RoleCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage, URL};
use Illuminate\Support\Str;

class DownloadController extends Controller
{
    public function __construct(private FeedService $feed) {}

    private function allowed(string $type): void
    {
        $setting = AppSetting::where('setting_key', $type === 'video' ? 'allow_video_download' : 'allow_photo_download')->first();
        abort_unless($setting?->typedValue() === true, 403, 'Download media ini dinonaktifkan.');
    }

    public function store(Request $request, int $post)
    {
        $item = $this->feed->post($post);
        $this->allowed($item->content_type);
        if ($user = $request->user()) {
            abort_unless($user->hasPermission('interact_posts')
                && !in_array($user->role?->name, RoleCatalog::PERSONAL_INTERACTION_ROLES, true), 403, 'Permission tidak tersedia.');
        }
        $limit = AppSetting::where('setting_key','max_download_items_per_session')->first()?->typedValue();
        $limit = is_numeric($limit) ? max(1, min(15, (int) $limit)) : 15;
        $data = $request->validate(['media_ids'=>['required','array','min:1','max:'.$limit], 'media_ids.*'=>['required','integer','distinct']]);
        $media = $item->media->whereIn('id', $data['media_ids']);
        abort_unless($media->count() === count($data['media_ids']),422,'Media tidak berasal dari post ini.');
        foreach ($media as $file) {
            abort_unless(FeedMedia::canPresent($file, $item->content_type), 422, 'Media tidak tersedia.');
            if ($item->content_type === 'video') {
                abort_unless(FeedMedia::isLocalMp4($file), 422, 'Hanya video MP4 lokal yang dapat diunduh.');
            }
        }
        abort_if($item->content_type === 'video' && !$item->listing->permitsVideoDownload(), 403);
        $session = DB::transaction(function () use ($request, $item, $media) {
            $session = DownloadSession::create(['post_id'=>$item->id,'user_id'=>$request->user()?->id,'session_id'=>app(InteractionController::class)->identity($request)]);
            foreach ($media as $file) $session->items()->create(['post_media_id'=>$file->id]);
            return $session;
        });
        return response()->json(['session_id'=>$session->id, 'files'=>$session->items->map(fn ($file) => [
            'media_id'=>$file->post_media_id,
            'url'=>URL::temporarySignedRoute('feed.download.file', now()->addMinutes(5), ['item'=>$file->id], absolute: false),
        ])],201);
    }

    public function file(Request $request, DownloadItem $item)
    {
        $media = $item->media;
        $post = $this->feed->visible()->findOrFail($media->post_id);
        $this->allowed($post->content_type);
        abort_unless($item->downloadSession?->post_id === $post->id && FeedMedia::canPresent($media, $post->content_type), 404);
        $name = null;
        if ($post->content_type === 'video') {
            abort_unless($post->listing->permitsVideoDownload(), 403);
            abort_unless(FeedMedia::isLocalMp4($media), 404);
            $name = self::videoName($post);
        }
        if (AgentMedia::path($media)) return AgentMedia::response($media, $name ?? basename($media->file_url));
        $path = FeedMedia::localPath($media->file_url);
        if ($path) {
            $root = realpath(Storage::disk('public')->path(''));
            $file = realpath(Storage::disk('public')->path($path));
            abort_unless($root && $file && str_starts_with($file, $root.DIRECTORY_SEPARATOR),404);
            $headers = ['X-Content-Type-Options' => 'nosniff'];
            if ($name !== null) $headers['Content-Type'] = 'video/mp4';
            return response()->download($file, $name ?? basename($file), $headers);
        }
        $url = FeedMedia::url($media->file_url);
        abort_unless($url && str_starts_with($url, 'http'),404,'File tidak tersedia.');
        // Never fetch a remote URL server-side. Original CDN bytes, no watermark.
        return redirect()->away($url);
    }

    private static function videoName(Post $post): string
    {
        $slug = rtrim(substr(Str::slug($post->listing->title), 0, 120), '-');
        return 'Promerty-'.ucwords($slug ?: 'Video-'.$post->id, '-').'.mp4';
    }
}
