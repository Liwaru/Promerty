<?php

namespace App\Services;

use App\Models\{PostMedia,User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Sequential, resumable multipart chunks on the same private disk as AgentMedia. */
final class AgentVideoUpload
{
    public const MAX_BYTES = 750000000;
    private const TTL = 86400;

    public function begin(User $actor, int $listing, int $size, string $sha): array
    {
        abort_unless($size > 0 && $size <= min(self::MAX_BYTES, config('agent.video_max_bytes')), 422, 'Video melebihi batas ukuran.');
        $id = substr(hash('sha256', "$actor->id:$listing:$sha"), 0, 48);

        return $this->locked($actor, $listing, function (string $dir) use ($actor, $listing, $size, $sha, $id) {
            $this->prune($dir);
            $state = $this->read($dir, $id);
            if ($state !== null) {
                abort_unless($state['size'] === $size && $state['sha'] === $sha, 409, 'Identitas upload tidak cocok.');
                return $this->result($dir, $id, $state);
            }
            $workflow = app(AgentListingWorkflow::class);
            $workflow->editable($workflow->owned($actor, $listing));
            $active = 0;
            $reserved = 0;
            $receipts = glob($dir.'/*.json') ?: [];
            abort_if(count($receipts) >= 256, 429, 'Kapasitas antrean media sedang penuh.');
            foreach ($receipts as $file) {
                $other = $this->read($dir, basename($file, '.json'));
                if ($other === null || $other['media'] !== null) continue;
                abort_if($other['actor'] === $actor->id, 409, 'Selesaikan atau batalkan upload video sebelumnya.');
                $active++;
                $reserved += 2 * $other['size'];
            }
            abort_if($active >= config('agent.video_upload_slots'), 429, 'Slot upload video sedang penuh.');
            $this->capacity($dir, $reserved + 2 * $size);
            $state = ['actor'=>$actor->id, 'listing'=>$listing, 'size'=>$size, 'sha'=>$sha,
                'chunk'=>(int)config('agent.video_chunk_bytes'), 'expires'=>time()+self::TTL, 'media'=>null];
            $this->save($dir, $id, $state);
            return $this->result($dir, $id, $state);
        });
    }

    public function append(User $actor, int $listing, string $id, int $offset, string $sha, UploadedFile $upload): array
    {
        return $this->locked($actor, $listing, function (string $dir) use ($actor, $listing, $id, $offset, $sha, $upload) {
            $state = $this->owned($dir, $id, $actor, $listing);
            abort_if($state['media'] !== null, 409, 'Video sudah tersimpan.');
            $workflow = app(AgentListingWorkflow::class);
            $workflow->editable($workflow->owned($actor, $listing));
            $path = $dir.'/'.$id.'.part';
            $current = $this->offset($path, $state);
            $expected = min($state['chunk'], $state['size'] - $offset);
            abort_if($offset < 0 || $offset >= $state['size'] || $offset % $state['chunk'] !== 0
                || $upload->getSize() !== $expected || !hash_equals($sha, hash_file('sha256', $upload->getPathname())),
                422, 'Ukuran atau checksum chunk tidak cocok.');
            abort_if($offset > $current, 409, 'Ada chunk sebelumnya yang belum diterima.');
            if ($offset < $current) {
                $part = file_get_contents($path, false, null, $offset, $expected);
                abort_unless(is_string($part) && hash_equals($sha, hash('sha256', $part)), 409, 'Isi chunk ulang tidak cocok.');
                return $this->result($dir, $id, $state);
            }
            $this->capacity($dir, $expected);
            $input = @fopen($upload->getPathname(), 'rb');
            if (!$input) abort(422, 'Chunk tidak dapat dibaca.');
            try {
                $out = @fopen($path, 'c+b');
                if (!$out) abort(507, 'Penyimpanan upload tidak tersedia.');
                try {
                    if (ftruncate($out, $offset) && fseek($out, $offset) === 0
                        && @stream_copy_to_stream($input, $out) === $expected && @fflush($out)) {
                        return ['id'=>$id, 'offset'=>$offset+$expected, 'chunk_bytes'=>$state['chunk'], 'media_id'=>null];
                    }
                    @ftruncate($out, $offset);
                    abort(507, 'Chunk belum tersimpan utuh.');
                } finally { fclose($out); }
            } finally { fclose($input); }
        });
    }

    public function finish(User $actor, int $listing, string $id): array
    {
        return $this->locked($actor, $listing, function (string $dir) use ($actor, $listing, $id) {
            $state = $this->owned($dir, $id, $actor, $listing);
            if ($state['media'] !== null) return $this->result($dir, $id, $state);
            $path = $dir.'/'.$id.'.part';
            abort_unless($this->offset($path, $state) === $state['size'], 409, 'Upload belum lengkap.');
            abort_unless(hash_equals($state['sha'], hash_file('sha256', $path)), 422, 'Checksum video akhir tidak cocok.');
            $this->capacity($dir, $state['size']);
            $media = app(AgentMedia::class)->upload($actor, $listing, new UploadedFile($path, 'video.mp4', test:true), $id, $state['sha']);
            $state['media'] = $media->id;
            $this->save($dir, $id, $state);
            @unlink($path);
            if (config('agent.video_optimization') && in_array(config('queue.default'), ['database','redis'], true)) {
                try {
                    AgentMedia::optimizationState($media,'queued');
                    \App\Jobs\OptimizeAgentVideo::dispatch($media->id);
                } catch (\Throwable) {
                    AgentMedia::optimizationState($media,'failed');
                    \Illuminate\Support\Facades\Log::warning('agent.video_optimization_dispatch_failed');
                }
            }
            return $this->result($dir, $id, $state);
        });
    }

    public function cancel(User $actor, int $listing, string $id): void
    {
        $this->locked($actor, $listing, function (string $dir) use ($actor, $listing, $id) {
            $state = $this->owned($dir, $id, $actor, $listing);
            abort_if($state['media'] !== null, 409, 'Video sudah tersimpan.');
            $this->removeStage($dir, $id);
        });
    }

    private function locked(User $actor, int $listing, callable $action): mixed
    {
        abort_unless(config('agent.resumable_video'), 404);
        app(AgentListingWorkflow::class)->owned($actor, $listing);
        $disk = Storage::disk('local');
        $disk->makeDirectory('agent-video-staging');
        $root = realpath($disk->path(''));
        $dir = realpath($disk->path('agent-video-staging'));
        abort_unless($root && $dir && $dir === rtrim($root, '/\\').DIRECTORY_SEPARATOR.'agent-video-staging', 507, 'Staging tidak tersedia.');
        $lock = @fopen($dir.'/registry.lock', 'c+b');
        abort_unless($lock, 507, 'Staging tidak tersedia.');
        try {
            abort_unless(flock($lock, LOCK_EX | LOCK_NB), 423, 'Staging sibuk. Coba lanjutkan lagi.');
            clearstatcache();
            return $action($dir);
        } catch (HttpExceptionInterface|\Illuminate\Validation\ValidationException $error) {
            throw $error;
        } catch (\Throwable) {
            abort(503, 'Penyimpanan video belum dapat dikonfirmasi.');
        } finally { fclose($lock); }
    }

    private function capacity(string $dir, int $need): void
    {
        $free = disk_free_space($dir);
        abort_if($free === false || $free < $need + config('agent.video_disk_reserve_bytes'), 507, 'Ruang penyimpanan video tidak cukup.');
    }

    private function read(string $dir, string $id): ?array
    {
        abort_unless(preg_match('/^[a-f0-9]{48}$/D', $id), 404);
        $file = $dir.'/'.$id.'.json';
        if (!is_file($file)) return null;
        return json_decode(file_get_contents($file, false, null, 0, 4096), true, flags:JSON_THROW_ON_ERROR);
    }

    private function owned(string $dir, string $id, User $actor, int $listing): array
    {
        $state = $this->read($dir, $id);
        abort_unless($state && $state['actor'] === $actor->id && $state['listing'] === $listing && $state['expires'] > time(), 404);
        return $state;
    }

    private function save(string $dir, string $id, array $state): void
    {
        $temp = $dir.'/'.$id.'.tmp';
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        abort_unless(@file_put_contents($temp, $json) === strlen($json) && @rename($temp, $dir.'/'.$id.'.json'),
            507, 'Status upload belum dapat disimpan.');
    }

    private function prune(string $dir): void
    {
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if ($this->read($dir, $id)['expires'] <= time()) $this->removeStage($dir, $id);
        }
    }

    private function removeStage(string $dir, string $id): void
    {
        foreach (['part', 'tmp', 'json'] as $ext) {
            $file = "$dir/$id.$ext";
            abort_if(is_file($file) && !@unlink($file), 507, 'Staging belum dapat dibersihkan.');
        }
    }

    private function offset(string $path, array $state): int
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : 0;
        abort_if($size === false || $size > $state['size'], 409, 'Ukuran staging tidak cocok.');
        return $size === $state['size'] ? $size : intdiv($size, $state['chunk'])*$state['chunk'];
    }

    private function result(string $dir, string $id, array $state): array
    {
        if ($state['media'] !== null) {
            $media = PostMedia::find($state['media']);
            abort_unless($media && AgentMedia::exists($media), 409, 'Video tersimpan tidak dapat dikonfirmasi.');
        }
        return ['id'=>$id, 'offset'=>$state['media'] === null ? $this->offset($dir.'/'.$id.'.part', $state) : $state['size'],
            'chunk_bytes'=>$state['chunk'], 'media_id'=>$state['media'], 'sha256'=>$state['media'] === null ? null : $state['sha']];
    }
}
