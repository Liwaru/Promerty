<?php
/** Long-caption presentation fixture, scoped to the existing synthetic video QA post. */
declare(strict_types=1);
$app=require __DIR__.'/customer-environment.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db')->db!=='promerty_qa')throw new RuntimeException('QA database guard');
$root=dirname(__DIR__,2);$run=$root.'/storage/app/private/real-video-20261113';
$manifest=json_decode(file_get_contents($run.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$post=App\Models\Post::whereKey($manifest['posts']['portrait'])->where('user_id',$manifest['agent'])->whereHas('listing',fn($q)=>$q->where('listing_code','REAL-VIDEO-20261113-PORTRAIT'))->firstOrFail();
$backup=$run.'/caption-original.json';if(!is_file($backup))file_put_contents($backup,json_encode(['post_id'=>$post->id,'caption'=>$post->caption],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$post->update(['caption'=>'QA MEDIA ASLI portrait — listing dan lokasi sintetis, video bukan bukti kondisi properti. Caption panjang ini khusus menguji viewer desktop Promerty: informasi agen, kategori, lokasi dan harga harus tetap terbaca; teks dapat dibuka melalui Selengkapnya tanpa memasuki area video 9:16 atau menutupi kontrol playback. Seluruh spesifikasi dan harga listing adalah data pengujian, bukan penawaran. Pemeriksaan hanya menggunakan database QA terisolasi dan video asli yang disediakan pemilik workspace.']);
echo json_encode(['database'=>'promerty_qa','post_id'=>$post->id,'caption_chars'=>mb_strlen($post->caption),'original_saved_privately'=>true]).PHP_EOL;
