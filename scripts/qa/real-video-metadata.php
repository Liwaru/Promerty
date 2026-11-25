<?php
/** Read-only ISO BMFF inspection. No decoding, transcoding or dependency installation. */
declare(strict_types=1);
function qaAtoms(string $s, int $start=0, ?int $end=null): array {
    $out=[]; $end ??= strlen($s);
    for($p=$start;$p+8<=$end;){$n=unpack('N',substr($s,$p,4))[1];$type=substr($s,$p+4,4);$h=8;
        if($n===1){$v=unpack('Nhi/Nlo',substr($s,$p+8,8));$n=$v['hi']*4294967296+$v['lo'];$h=16;}
        if($n===0)$n=$end-$p;if($n<$h||$p+$n>$end)break;
        $out[]=['type'=>$type,'data'=>substr($s,$p+$h,$n-$h)];$p+=$n;
    }return $out;
}
function qaChild(string $s,string $type): string {foreach(qaAtoms($s) as $a)if($a['type']===$type)return $a['data'];return '';}
function qaU32(string $s,int $p): int {return unpack('N',substr($s,$p,4))[1];}
function qaVideoMetadata(string $file): array {
    $s=file_get_contents($file);$ftyp=qaChild($s,'ftyp');$moov=qaChild($s,'moov');
    if(!$ftyp||!$moov)throw new RuntimeException('Not a supported ISO BMFF container');
    $r=['name'=>basename($file),'bytes'=>strlen($s),'sha256'=>hash('sha256',$s),'mime'=>(new finfo(FILEINFO_MIME_TYPE))->file($file),'major_brand'=>substr($ftyp,0,4),'tracks'=>[]];
    foreach(qaAtoms($moov) as $a){if($a['type']!=='trak')continue;$mdia=qaChild($a['data'],'mdia');$hdlr=qaChild($mdia,'hdlr');$mdhd=qaChild($mdia,'mdhd');$v=ord($mdhd[0]);$scale=qaU32($mdhd,$v?20:12);$duration=$v?(qaU32($mdhd,24)*4294967296+qaU32($mdhd,28)):qaU32($mdhd,16);
        $stsd=qaChild(qaChild(qaChild($mdia,'minf'),'stbl'),'stsd');$entries=qaAtoms($stsd,8);$entry=$entries[0];$t=['handler'=>substr($hdlr,8,4),'codec_tag'=>$entry['type'],'duration_seconds'=>$duration/$scale];
        if($t['handler']==='vide'){$d=$entry['data'];$t['width']=unpack('n',substr($d,24,2))[1];$t['height']=unpack('n',substr($d,26,2))[1];$tkhd=qaChild($a['data'],'tkhd');$mp=ord($tkhd[0])?52:40;$mat=[];for($i=0;$i<9;$i++){$x=qaU32($tkhd,$mp+4*$i);if($x>=2147483648)$x-=4294967296;$mat[]=$x;}$t['track_matrix']=$mat;$t['rotation_degrees']=round(rad2deg(atan2($mat[1],$mat[0])));
            $t['codec']=match($entry['type']){'hvc1','hev1'=>'HEVC / H.265','avc1','avc3'=>'H.264 / AVC',default=>$entry['type']};
            $pasp=qaChild(substr($d,78),'pasp');$t['pixel_aspect_ratio']=$pasp?[qaU32($pasp,0),qaU32($pasp,4)]:[1,1];
            $t['display_width']=in_array(abs($t['rotation_degrees']),[90,270])?$t['height']:$t['width'];$t['display_height']=in_array(abs($t['rotation_degrees']),[90,270])?$t['width']:$t['height'];$t['aspect_ratio']=$t['display_width']/$t['display_height'];$t['orientation']=$t['aspect_ratio']>1?'landscape':'portrait';
        }elseif($t['handler']==='soun'){$d=$entry['data'];$t['channels']=unpack('n',substr($d,16,2))[1];$t['sample_rate']=qaU32($d,24)/65536;$esds=qaChild(substr($d,28),'esds');$t['codec']=$entry['type'];if(preg_match('/\x04[\x80-\xff]{0,3}[\x00-\x7f](.)/s',$esds,$m)&&ord($m[1])===64)$t['codec']='AAC (MPEG-4 Audio object type 0x40)';}
        $r['tracks'][]=$t;
    }return $r;
}
if(realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){$files=glob(dirname(__DIR__,2).'/storage/app/qa-media/videos/*');$out=[];foreach($files as $f)if(is_file($f))$out[]=qaVideoMetadata($f);echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;}
