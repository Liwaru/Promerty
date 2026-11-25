<?php

namespace App\Services;

use App\Models\{PostMedia, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\ValidationException;

class AgentMedia
{
    public static function path(PostMedia $media): ?string
    {
        if (!preg_match('~^agent-private/([a-f0-9]{48}\.(?:jpg|png|webp|mp4))$~D',$media->file_url,$m)) return null;
        return 'agent-listings/'.$m[1];
    }
    public static function exists(PostMedia $media): bool
    {
        $path=self::path($media);
        return $path !== null && self::storedFile($path) !== null;
    }

    private static function directory(bool $create = false): ?string
    {
        $disk = Storage::disk('local');
        if ($create && !$disk->makeDirectory('agent-listings')) {
            return null;
        }
        $root = realpath($disk->path(''));
        $directory = realpath($disk->path('agent-listings'));

        // A link at the collection directory must not move uploads outside this disk.
        return $root && $directory && is_dir($directory)
            && $directory === rtrim($root, '/\\').DIRECTORY_SEPARATOR.'agent-listings'
            ? $directory : null;
    }

    private static function storedFile(string $path): ?string
    {
        $directory = self::directory();
        if ($directory === null) {
            return null;
        }
        $expected = $directory.DIRECTORY_SEPARATOR.basename($path);

        return is_file($expected) && realpath($expected) === $expected ? $expected : null;
    }

    public static function response(PostMedia $media, ?string $downloadName = null)
    {
        $path=self::path($media); abort_unless($path,404);
        $file=self::storedFile($path);
        abort_unless($file,404);
        $mime=match(pathinfo($path,PATHINFO_EXTENSION)){'jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','mp4'=>'video/mp4'};
        if ($downloadName === null && $mime === 'video/mp4') {
            $file = self::storedFile($path.'.playback') ?? $file;
        }
        $response=response()->file($file,['Content-Type'=>$mime,'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
        if ($downloadName !== null) $response->setContentDisposition('attachment', $downloadName);
        return $response;
    }

    public static function originalVideo(PostMedia $media): ?string
    {
        $path = self::path($media);
        return $path && str_ends_with($path, '.mp4') ? self::storedFile($path) : null;
    }

    public static function optimizationState(PostMedia $media, ?string $state = null): ?string
    {
        $source = self::originalVideo($media);
        if ($source === null) return null;
        $states = ['queued','optimizing','optimized','original','failed'];
        $statusFile = $source.'.optimization';
        if (in_array($state,$states,true)) {
            @file_put_contents($statusFile,$state,LOCK_EX);
            return $state;
        }
        $value = is_file($statusFile) ? @file_get_contents($statusFile,false,null,0,16) : null;
        return in_array($value,$states,true) ? $value : null;
    }
    public function upload(User $actor,int $id,UploadedFile $file,?string $uploadId=null,?string $sha256=null): PostMedia
    {
        abort_if($uploadId!==null && (!preg_match('/^[a-f0-9]{48}$/D',$uploadId)
            || $sha256===null || !preg_match('/^[a-f0-9]{64}$/D',$sha256)),422);
        $stored=null;
        try {
            return DB::transaction(function () use ($actor,$id,$file,$uploadId,$sha256,&$stored) {
                $workflow=app(AgentListingWorkflow::class);$listing=$workflow->owned($actor,$id,true);
                if ($uploadId !== null) {
                    $existing=$listing->posts()->oldest('id')->first()?->media()
                        ->where('file_url','agent-private/'.$uploadId.'.mp4')->first();
                    if ($existing && ($original=self::originalVideo($existing))
                        && hash_equals($sha256,hash_file('sha256',$original))) return $existing;
                }
                $workflow->editable($listing);
                $manifest=app(PrivateMediaValidator::class)->inspectForListing($actor,$listing,$file,$uploadId!==null);
                $post=$workflow->post($listing);$media=$post->media()->get();$type=$manifest['mime']==='video/mp4'?'video':'image';
                $fallback=$uploadId!==null && $media->count()===1 && ExternalListingVideo::parse($media->first()->file_url)
                    ? $media->first() : null;
                if ($media->count()>=(int)config('agent.media_max_count') || (!$fallback && $media->isNotEmpty() &&
                    ($media->first()->media_type!==$type || $type==='video'))) {
                    throw ValidationException::withMessages(['media'=>'Gunakan satu video atau maksimal '.config('agent.media_max_count').' foto.']);
                }
                if ($uploadId!==null) $manifest['generated_name']=$uploadId.'.mp4';
                $candidate='agent-listings/'.$manifest['generated_name'];
                $directory=self::directory(true);
                if ($directory === null) throw ValidationException::withMessages(['media'=>'Penyimpanan gagal atau nama file berbenturan.']);
                $destination = $directory.DIRECTORY_SEPARATOR.$manifest['generated_name'];
                if ($uploadId !== null) {
                    // A hard link adds a private name for the verified staging inode without copying
                    // hundreds of MB inside the DB transaction. Keep staging until commit.
                    if (@link($file->getRealPath(),$destination)) {
                        $stored=$candidate;
                    } else {
                        $existingFile = self::storedFile($candidate);
                        if (!$existingFile || filesize($existingFile)!==$manifest['bytes']
                            || !hash_equals($sha256,hash_file('sha256',$existingFile))) {
                            throw ValidationException::withMessages(['media'=>'Volume media tidak mendukung finalisasi atomik atau file berbenturan.']);
                        }
                    }
                } else {
                    $output=@fopen($destination,'xb');
                    if (!$output) throw ValidationException::withMessages(['media'=>'Penyimpanan gagal atau nama file berbenturan.']);
                    $stored=$candidate;
                    $input=@fopen($file->getRealPath(),'rb');
                    if(!$input){fclose($output);throw ValidationException::withMessages(['media'=>'File unggahan tidak dapat dibaca.']);}
                    try {
                        if(stream_copy_to_stream($input,$output)!==$manifest['bytes'] || !fflush($output))
                            throw new \RuntimeException('Incomplete media write.');
                    } finally { fclose($input);fclose($output); }
                }
                $post->update(['content_type'=>$type==='video'?'video':'photo']);
                if ($fallback) $fallback->update(['sort_order'=>2]);
                $item=$post->media()->create(['media_type'=>$type,'file_url'=>'agent-private/'.$manifest['generated_name'],
                    'thumbnail_url'=>$type==='image'?'agent-private/'.$manifest['generated_name']:null,
                    'sort_order'=>$fallback ? 1 : ($media->max('sort_order')??0)+1,'file_size_bytes'=>$manifest['bytes'],
                    'width'=>$manifest['width'],'height'=>$manifest['height'],'duration_seconds'=>$manifest['duration_seconds']]);
                app(SecurityAudit::class)->record('listing.media_uploaded',$actor,['listing_id'=>$listing->id]);
                return $item;
            });
        } catch (\Throwable $error) {
            if ($stored && ($filePath=self::storedFile($stored))) @unlink($filePath);
            if ($error instanceof \Illuminate\Database\QueryException
                && DB::connection()->getDriverName()==='sqlite'
                && in_array((int)($error->errorInfo[1]??0),[5,6],true)) {
                abort(409,'Listing sedang diperbarui. Muat ulang daftar media sebelum mencoba lagi.');
            }
            throw $error;
        }
    }
    public function attachExternal(User $actor, int $listingId, string $url, ?string $provider = null): PostMedia
    {
        $external = ExternalListingVideo::parse($url);
        if (!$external || ($provider !== null && $external['provider'] !== $provider)) {
            throw ValidationException::withMessages(['url' => 'Gunakan URL video TikTok atau Instagram Reels publik yang lengkap.']);
        }

        return DB::transaction(function () use ($actor, $listingId, $external) {
            $workflow = app(AgentListingWorkflow::class);
            $listing = $workflow->owned($actor, $listingId, true);
            $workflow->editable($listing);
            $post = $workflow->post($listing);
            $existing = $post->media()->get();
            if ($existing->count() === 1 && $existing->first()->media_type === 'video'
                && $existing->first()->file_url === $external['url']) {
                return $existing->first();
            }
            if ($existing->isNotEmpty()) {
                throw ValidationException::withMessages(['url' => 'Hapus media lama sebelum menambahkan tautan video.']);
            }
            $post->update(['content_type' => 'video']);
            $media = $post->media()->create(['media_type' => 'video', 'file_url' => $external['url'], 'sort_order' => 1]);
            app(SecurityAudit::class)->record('listing.media_linked', $actor, ['listing_id' => $listing->id]);
            return $media;
        });
    }

    public function remove(User $actor,int $listingId,int $mediaId): bool
    {
        $path=DB::transaction(function () use ($actor,$listingId,$mediaId) {
            $workflow=app(AgentListingWorkflow::class);$listing=$workflow->owned($actor,$listingId,true);$workflow->editable($listing);
            $post=$workflow->post($listing);$media=$post->media()->findOrFail($mediaId);$path=self::path($media);
            $external = $media->media_type === 'video' && ExternalListingVideo::parse($media->file_url);
            abort_unless($path || $external,409,'Media existing dikelola melalui workflow asalnya.');
            abort_if($path && PostMedia::where('file_url',$media->file_url)->count()!==1,409,'Media bersama tidak dapat dihapus di sini.');
            $media->delete();
            app(SecurityAudit::class)->record('listing.media_removed',$actor,['listing_id'=>$listing->id]);
            // Only the exact owned upload is removed, never a directory or other media.
            return $path;
        });
        if ($path === null) return false;
        $filePath = self::storedFile($path);
        if ($filePath !== null) {
            $playback = self::storedFile($path.'.playback');
            if ($playback !== null) @unlink($playback);
            $status = self::storedFile($path.'.optimization');
            if ($status !== null) @unlink($status);
            return @unlink($filePath);
        }

        return !Storage::disk('local')->exists($path);
    }
}
