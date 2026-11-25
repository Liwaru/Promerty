<?php
/** One-shot real media fixture preparation; no operational environment bootstrap. */
declare(strict_types=1);
use App\Models\{User,Listing,Location,SecondaryProperty,Post};
use Illuminate\Support\Facades\DB;
require __DIR__.'/real-video-metadata.php';
$root=dirname(__DIR__,2);$run=$root.'/storage/app/private/real-video-20261113';
if(is_file($run.'/manifest.json'))throw new RuntimeException('Run exists; refusing duplicate fixtures');
$files=glob($root.'/storage/app/qa-media/videos/*');$media=[];
foreach($files as $f)if(is_file($f)){$m=qaVideoMetadata($f);$v=array_values(array_filter($m['tracks'],fn($t)=>$t['handler']==='vide'))[0];if(isset($media[$v['orientation']]))throw new RuntimeException('Duplicate orientation');$media[$v['orientation']]=['file'=>$m,'video'=>$v];}
if(count($media)!==2||!isset($media['portrait'],$media['landscape']))throw new RuntimeException('Need one portrait and one landscape');
$app=require __DIR__.'/customer-environment.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(DB::connection()->getDatabaseName()!=='promerty_qa'||DB::selectOne('SELECT DATABASE() AS db')->db!=='promerty_qa')throw new RuntimeException('QA connection identity guard');
// Exercise the operational-name boundary using the QA principal, without reading operational data.
$denied=false;try{DB::select('SELECT 1 FROM promerty.users LIMIT 0');}catch(Throwable $e){$denied=in_array((int)($e->errorInfo[1]??0),[1044,1142],true);}
if(!$denied)throw new RuntimeException('QA principal must be denied operational access');
$template=User::where('status','active')->whereHas('role',fn($q)=>$q->where('name','agent'))->firstOrFail();
$base=Listing::where('agent_id',$template->id)->firstOrFail();
if(!is_dir($run))mkdir($run,0700,true);
$credentials=['username'=>'promerty-video-qa','password'=>bin2hex(random_bytes(16))];
file_put_contents($run.'/access.json',json_encode($credentials,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
file_put_contents($run.'/metadata.json',json_encode(array_values(array_column($media,'file')),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$ids=['run'=>'REAL-VIDEO-20261113','database'=>'promerty_qa','port'=>8017,'qa_principal_denied_operational'=>true,'posts'=>[],'listings'=>[],'locations'=>[],'secondary'=>[],'media'=>[]];
DB::transaction(function()use(&$ids,$template,$base,$media){
    $agent=$template->replicate();$agent->fill(['name'=>'QA Video Agent — sintetis','username'=>'qa.real.video.20261113','email'=>'qa.real.video.20261113@example.invalid','phone'=>null,'password'=>bin2hex(random_bytes(32)),'photo'=>null,'bio'=>'Akun pengujian QA. Bukan agen pemasaran nyata.']);$agent->remember_token=null;$agent->save();$ids['agent']=$agent->id;
    foreach(['portrait','landscape'] as $i=>$orientation){$m=$media[$orientation];$key=$ids['run'].'-'.strtoupper($orientation);$area=$i===0?'Batam Center':'Bengkong';
        $loc=Location::create(['label'=>$area.' (QA sintetis)','district'=>$area,'city'=>'Batam','province'=>'Kepulauan Riau','visibility'=>'approximate','created_by'=>$agent->id]);$ids['locations'][]=$loc->id;
        $source=SecondaryProperty::create(['property_code'=>$key,'category_id'=>$base->category_id,'location_id'=>$loc->id,'title'=>'Properti sintetis QA '.$orientation,'created_by'=>$agent->id]);$ids['secondary'][]=$source->id;
        $listing=$base->replicate();$listing->fill(['agent_id'=>$agent->id,'listing_code'=>$key,'listing_type'=>'secondary','transaction_type'=>'sale','project_id'=>null,'secondary_property_id'=>$source->id,'title'=>'QA Video '.ucfirst($orientation).' — Properti Sintetis '.$area,'description'=>'DATA PENGUJIAN, BUKAN PENAWARAN PROPERTI. Spesifikasi dan harga adalah sintetis. Video asli hanya untuk menguji playback; tidak diklaim menggambarkan properti atau lokasi ini.','price'=>$i===0?1250000000:980000000,'price_period'=>'total','bedrooms'=>3,'bathrooms'=>2,'land_area'=>120,'building_area'=>90,'floors'=>2,'status'=>'active']);$listing->save();$ids['listings'][]=$listing->id;
        $post=Post::create(['user_id'=>$agent->id,'listing_id'=>$listing->id,'content_type'=>'video','caption'=>'QA MEDIA ASLI '.$orientation.' — listing dan lokasi sintetis, video bukan bukti kondisi properti.','status'=>'published','moderation_status'=>'active','published_at'=>now()->subSeconds($i+2)]);$ids['posts'][$orientation]=$post->id;
        $url='qa-real-video/'.$orientation.'.mp4';$row=$post->media()->create(['media_type'=>'video','file_url'=>$url,'thumbnail_url'=>null,'sort_order'=>1,'file_size_bytes'=>$m['file']['bytes'],'duration_seconds'=>$m['video']['duration_seconds'],'width'=>$m['video']['display_width'],'height'=>$m['video']['display_height']]);
        $ids['media'][$orientation]=['id'=>$row->id,'url'=>'/storage/'.$url,'source'=>$m['file']['name'],'sha256'=>$m['file']['sha256']];
    }
});
file_put_contents($run.'/manifest.json',json_encode($ids,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
echo json_encode($ids,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
