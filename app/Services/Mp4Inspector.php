<?php

namespace App\Services;

/** Bounded ISO-BMFF structural inspection, not a decoder or malware scanner. */
class Mp4Inspector
{
    private function reject(): never { throw new \UnexpectedValueException('Unsupported or damaged MP4.'); }
    private function u32(string $s, int $offset): int
    {
        if (strlen($s) < $offset + 4) $this->reject();
        return unpack('N', substr($s, $offset, 4))[1];
    }
    private function boxes(string $bytes): array
    {
        $result=[]; $offset=0; $length=strlen($bytes);
        while ($offset < $length) {
            if (count($result)>10000 || $length-$offset<8) $this->reject();
            $size=$this->u32($bytes,$offset); $header=8;
            if ($size===1) { if ($this->u32($bytes,$offset+8)!==0) $this->reject(); $size=$this->u32($bytes,$offset+12); $header=16; }
            if ($size===0) $size=$length-$offset;
            if ($size<$header || $offset+$size>$length) $this->reject();
            $result[]=['type'=>substr($bytes,$offset+4,4),'data'=>substr($bytes,$offset+$header,$size-$header)];
            $offset+=$size;
        }
        return $result;
    }
    private function get(array $boxes, string $type): string
    {
        $matches=array_values(array_filter($boxes,fn($b)=>$b['type']===$type));
        if (count($matches)!==1) $this->reject();
        return $matches[0]['data'];
    }
    private function containsChunk(array $ranges, int $position, int $bytes): bool
    {
        // Top-level boxes are encountered in order. Bound the lookup even when
        // a file contains many chunks and many disjoint mdat boxes.
        $low=0;
        $high=count($ranges)-1;
        while ($low <= $high) {
            $middle=intdiv($low+$high,2);
            [$start,$end]=$ranges[$middle];
            if ($position < $start) $high=$middle-1;
            elseif ($position >= $end) $low=$middle+1;
            else return $bytes <= $end-$position;
        }
        return false;
    }
    public function inspect(string $path): array
    {
        $size=filesize($path); $stream=fopen($path,'rb'); $moov=null; $ftyp=false; $mdat=[]; $count=0;
        try {
            for ($offset=0;$offset<$size;) {
                if (++$count>10000 || $size-$offset<8) $this->reject();
                fseek($stream,$offset); $header=fread($stream,16);
                $length=$this->u32($header,0); $type=substr($header,4,4); $h=8;
                if ($length===1) { if($this->u32($header,8)!==0)$this->reject(); $length=$this->u32($header,12); $h=16; }
                if ($length===0) $length=$size-$offset;
                if ($length<$h || $offset+$length>$size) $this->reject();
                if ($type==='ftyp') $ftyp=$length>=16;
                if ($type==='mdat' && $length>$h) $mdat[]=[$offset+$h,$offset+$length];
                if ($type==='moov') {
                    if ($moov!==null || $length>8*1024*1024) $this->reject();
                    fseek($stream,$offset+$h); $moov=fread($stream,$length-$h);
                }
                $offset+=$length;
            }
        } finally { fclose($stream); }
        if (!$ftyp || !$mdat || $moov===null) $this->reject();
        $tracks=array_filter($this->boxes($moov),fn($b)=>$b['type']==='trak'); $video=null;
        foreach ($tracks as $track) {
            $t=$this->boxes($track['data']); $mdia=$this->boxes($this->get($t,'mdia'));
            $handler=substr($this->get($mdia,'hdlr'),8,4);
            if (!in_array($handler,['vide','soun'],true)) $this->reject();
            $table=$this->boxes($this->get($this->boxes($this->get($mdia,'minf')),'stbl'));
            $stsd=$this->get($table,'stsd'); $entries=$this->boxes(substr($stsd,8));
            if ($this->u32($stsd,4)!==1 || count($entries)!==1
                || !in_array($entries[0]['type'],$handler==='vide'?['avc1','avc3','hvc1','hev1']:['mp4a'],true)) $this->reject();
            if ($handler==='vide') {
                $config=$this->get($this->boxes(substr($entries[0]['data'],78)),
                    in_array($entries[0]['type'],['avc1','avc3'],true)?'avcC':'hvcC');
                if (strlen($config)<(in_array($entries[0]['type'],['avc1','avc3'],true)?7:23) || ord($config[0])!==1) $this->reject();
            }
            $stsz=$this->get($table,'stsz'); $samples=$this->u32($stsz,8); $unit=$this->u32($stsz,4);
            if (!$samples || $samples>1000000 || (!$unit && strlen($stsz)!==12+4*$samples)) $this->reject();
            $total=$unit*$samples;
            if (!$unit) for($i=0;$i<$samples;$i++) $total+=$this->u32($stsz,12+4*$i);
            if ($total<=0 || $total>array_sum(array_map(fn($r)=>$r[1]-$r[0],$mdat))) $this->reject();
            $wide=(bool)array_filter($table,fn($b)=>$b['type']==='co64');
            $chunks=$this->get($table,$wide?'co64':'stco'); $n=$this->u32($chunks,4); $step=$wide?8:4;
            if (!$n || $n>1000000 || strlen($chunks)!==8+$n*$step) $this->reject();
            $stsc=$this->get($table,'stsc');$mapping=$this->u32($stsc,4);
            if(!$mapping||$mapping>$n||strlen($stsc)!==8+12*$mapping)$this->reject();
            $runs=[];$previous=0;
            for($j=0;$j<$mapping;$j++){
                $first=$this->u32($stsc,8+12*$j);$per=$this->u32($stsc,12+12*$j);
                if(($j===0&&$first!==1)||$first<=$previous||$first>$n||!$per||$per>$samples||$this->u32($stsc,16+12*$j)!==1)$this->reject();
                $runs[]=[$first,$per];$previous=$first;
            }
            $stts=$this->get($table,'stts');$times=$this->u32($stts,4);$timedSamples=0;
            if(!$times||$times>1000000||strlen($stts)!==8+8*$times)$this->reject();
            for($j=0;$j<$times;$j++){$timedSamples+=$this->u32($stts,8+8*$j);if(!$this->u32($stts,12+8*$j))$this->reject();}
            if($timedSamples!==$samples)$this->reject();
            $run=0;$sample=0;
            for($i=0;$i<$n;$i++) {
                if ($wide && $this->u32($chunks,8+$i*$step)!==0) $this->reject();
                $position=$this->u32($chunks,8+$i*$step+($wide?4:0));
                if(isset($runs[$run+1])&&$i+1>=$runs[$run+1][0])$run++;
                $bytes=0;
                for($j=0;$j<$runs[$run][1];$j++){
                    if($sample>=$samples)$this->reject();$bytes+=$unit?:$this->u32($stsz,12+4*$sample);$sample++;
                }
                if (!$this->containsChunk($mdat,$position,$bytes)) $this->reject();
            }
            if($sample!==$samples)$this->reject();
            if ($handler!=='vide') continue;
            if ($video!==null) $this->reject();
            $tkhd=$this->get($t,'tkhd'); $mdhd=$this->get($mdia,'mdhd');
            $v=ord($mdhd[0]??'x'); if(!in_array($v,[0,1],true))$this->reject();
            $scale=$this->u32($mdhd,$v?20:12);
            if($v && $this->u32($mdhd,24)!==0)$this->reject();
            $duration=$this->u32($mdhd,$v?28:16)/max(1,$scale);
            $width=$this->u32($tkhd,strlen($tkhd)-8)/65536; $height=$this->u32($tkhd,strlen($tkhd)-4)/65536;
            $matrix=ord($tkhd[0]??'x')===1?52:40;
            if ($this->u32($tkhd,$matrix)===0 && $this->u32($tkhd,$matrix+16)===0) [$width,$height]=[$height,$width];
            if(!$scale || $duration<=0 || $duration>14400 || $width<1 || $height<1 || $width>8000 || $height>8000)$this->reject();
            $video=['width'=>(int)$width,'height'=>(int)$height,'duration_seconds'=>round($duration,2)];
        }
        return $video ?? $this->reject();
    }
}
