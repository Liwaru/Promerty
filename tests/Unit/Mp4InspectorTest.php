<?php

namespace Tests\Unit;

use App\Services\Mp4Inspector;
use PHPUnit\Framework\TestCase;

class Mp4InspectorTest extends TestCase
{
    private function box(string $type,string $data):string{return pack('N',strlen($data)+8).$type.$data;}
    private function fixture(string $codec='avc1',int $offset=24,int $sampleSize=4):string
    {
        $b=fn($t,$d)=>$this->box($t,$d);
        $tkhd=str_repeat("\0",40).pack('N*',65536,0,0,0,65536,0,0,0,1073741824,720*65536,1280*65536);
        $mdhd=pack('N*',0,0,0,1000,2000);
        $config=$b('avcC',"\1".str_repeat("\0",6));
        $stsd=pack('N*',0,1).$b($codec,str_repeat("\0",78).$config);
        $stbl=$b('stsd',$stsd).$b('stsz',pack('N*',0,$sampleSize,1)).$b('stco',pack('N*',0,1,$offset))
            .$b('stsc',pack('N*',0,1,1,1,1)).$b('stts',pack('N*',0,1,1,2000));
        $mdia=$b('hdlr',str_repeat("\0",8).'vide').$b('mdhd',$mdhd).$b('minf',$b('stbl',$stbl));
        return $b('ftyp','isom'.pack('N',0)).$b('mdat','TEST').$b('moov',$b('trak',$b('tkhd',$tkhd).$b('mdia',$mdia)));
    }
    private function inspect(string $bytes):array
    {
        $path=tempnam(sys_get_temp_dir(),'qa-mp4-');
        try{file_put_contents($path,$bytes);return (new Mp4Inspector)->inspect($path);}
        finally{unlink($path);} // Only this test's newly-created synthetic fixture.
    }
    public function test_structural_portrait_metadata_is_retained_without_claiming_bitstream_decode():void
    {
        $this->assertSame(['width'=>720,'height'=>1280,'duration_seconds'=>2.0],$this->inspect($this->fixture()));
    }
    public function test_truncated_boxes_unsupported_codec_out_of_bounds_chunks_and_samples_are_rejected():void
    {
        foreach([substr($this->fixture(),0,-1),$this->fixture('encv'),$this->fixture('avc1',9000000),
            $this->fixture('avc1',24,5),str_repeat('not mp4',5),$this->box('ftyp','isom'.pack('N',0))]as$bytes){
            try{$this->inspect($bytes);$this->fail('Damaged/unsupported container accepted.');}
            catch(\UnexpectedValueException){$this->assertTrue(true);}
        }
    }

    public function test_chunks_must_fit_in_a_single_payload_even_with_multiple_media_boxes():void
    {
        $first=$this->box('ftyp','isom'.pack('N',0)).$this->box('mdat','TEST').$this->box('mdat','DATA');
        $valid=$first.substr($this->fixture(offset:36),28);
        $this->assertSame(720,$this->inspect($valid)['width']);
        $this->expectException(\UnexpectedValueException::class);
        $this->inspect($first.substr($this->fixture(offset:26),28));
    }
}
