<?php

namespace App\Services;

/** Lossless removal of terminal ISO-BMFF free boxes. The kept bytes are identical. */
final class LightMp4Optimization
{
    public function trim(string $source, callable $stillExists): array
    {
        $lock = fopen(dirname($source).'/.optimization-lock', 'c+b');
        if (!$lock) throw new \RuntimeException('Optimization lock unavailable.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Optimizer busy.');
            return $this->optimize($source, $stillExists);
        } finally { fclose($lock); }
    }

    private function optimize(string $source, callable $stillExists): array
    {
        $size = filesize($source);
        $result = ['outcome'=>'original', 'input_bytes'=>$size, 'output_bytes'=>$size];
        if (!$size || $size > AgentVideoUpload::MAX_BYTES || file_exists($source.'.playback')) return $result;
        $deadline = hrtime(true) + 90_000_000_000;
        $input = fopen($source, 'rb');
        if (!$input) throw new \RuntimeException('Source unavailable.');
        $temp = null;
        try {
            $kept = 0;
            for ($position=0, $count=0; $position<$size; $position+=$length) {
                if (++$count>10000 || fseek($input, $position)!==0 || $size-$position<8) return $result;
                $header = unpack('Nlength/a4type', fread($input, 8));
                $length = $header['length'] ?: $size-$position;
                // Leave extended-size layouts to the original playback path.
                if ($length<8 || $length>$size-$position) return $result;
                if ($header['type']!=='free') $kept = $position+$length;
            }
            if ($kept<=0 || $kept===$size) return $result;
            $free = disk_free_space(dirname($source));
            if ($free===false || $free<$kept+config('agent.video_disk_reserve_bytes')) return $result;
            $candidate = $source.'.'.bin2hex(random_bytes(8)).'.partial';
            $out = fopen($candidate, 'xb');
            if (!$out) throw new \RuntimeException('Candidate unavailable.');
            $temp=$candidate;
            $digest=hash_init('sha256');
            try {
                rewind($input);
                for ($written=0; $written<$kept;) {
                    if (hrtime(true)>$deadline) throw new \RuntimeException('Optimization timed out.');
                    $bytes = fread($input, min($kept-$written,1048576));
                    if (!$bytes || fwrite($out,$bytes)!==strlen($bytes)) throw new \RuntimeException('Incomplete candidate.');
                    $written += strlen($bytes);
                    hash_update($digest,$bytes);
                }
                if (!fflush($out)) throw new \RuntimeException('Candidate flush failed.');
            } finally { fclose($out); }
            $inspector = app(Mp4Inspector::class);
            if (!hash_equals(hash_final($digest),hash_file('sha256',$temp))
                || $inspector->inspect($source)!==$inspector->inspect($temp)
                || !$stillExists() || !is_file($source)) throw new \RuntimeException('Candidate no longer valid.');
            if (!rename($temp,$source.'.playback')) throw new \RuntimeException('Candidate activation failed.');
            $temp=null;
            return ['outcome'=>'optimized','input_bytes'=>$size,'output_bytes'=>$kept];
        } finally {
            fclose($input);
            if ($temp!==null) @unlink($temp);
        }
    }
}
