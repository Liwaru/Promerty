<?php
/** Synthetic Search fixtures only in the guarded QA database; never alters operational locations. */
declare(strict_types=1);
use App\Models\{Listing,Location,Post,Project,SecondaryProperty,User};
use Illuminate\Support\Facades\DB;
$app=require __DIR__.'/customer-environment.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(DB::connection()->getDatabaseName()!=='promerty_qa'||DB::selectOne('SELECT DATABASE() AS db')->db!=='promerty_qa')throw new RuntimeException('QA guard failed');
$manifest=dirname(__DIR__,2).'/storage/app/private/search-filter-fixture-20261112.json';
if(($argv[1]??'')==='cleanup'){
    if(!is_file($manifest))throw new RuntimeException('No fixture manifest');
    $ids=json_decode(file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
    if(($ids['prefix']??'')!=='FILTER-QA-20261112-')throw new RuntimeException('Fixture identity guard');
    DB::transaction(function()use($ids){
        DB::table('post_media')->whereIn('post_id',$ids['posts'])->delete();
        Post::withTrashed()->whereIn('id',$ids['posts'])->forceDelete();
        Listing::withTrashed()->whereIn('id',$ids['listings'])->where('listing_code','like',$ids['prefix'].'%')->forceDelete();
        Project::withTrashed()->whereIn('id',$ids['projects'])->forceDelete();
        SecondaryProperty::withTrashed()->whereIn('id',$ids['secondary'])->forceDelete();
        Location::withTrashed()->whereIn('id',$ids['locations'])->forceDelete();
    });unlink($manifest);echo "Search QA fixtures removed.\n";exit;
}
if(is_file($manifest))throw new RuntimeException('Fixture already exists; refusing duplicate creation');
$agent=User::where('status','active')->whereHas('role',fn($r)=>$r->where('name','agent'))->firstOrFail();
$base=Listing::where('agent_id',$agent->id)->firstOrFail();
$ids=['prefix'=>'FILTER-QA-20261112-','locations'=>[],'projects'=>[],'secondary'=>[],'listings'=>[],'posts'=>[]];
$cases=[['Batam Center','Batam','Kepulauan Riau'],['Nagoya','Batam','Kepulauan Riau'],['Bengkong','Batam','Kepulauan Riau'],['Tiban','Batam','Kepulauan Riau'],['Nongsa','Batam','Kepulauan Riau'],['Batu Aji','Batam','Kepulauan Riau'],['Baloi','Kota Batam','Kepri'],['Muka Kuning',' BATAM ','Kepulauan Riau'],['Nagoya','Jakarta','DKI Jakarta'],['Batam Center','Tanjung Pinang','Kepulauan Riau'],['Batam Center',null,null],['Batam Center','Batam Selatan','Kepulauan Riau'],['Batam Center','Batam','DKI Jakarta']];
DB::transaction(function()use(&$ids,$cases,$agent,$base){
    foreach($cases as $i=>[$label,$city,$province]){
        $key=$ids['prefix'].$i;$primary=$i%2===0;
        $location=Location::create(['label'=>$label.' (QA sintetis)','city'=>$city,'province'=>$province,'visibility'=>'approximate','created_by'=>$agent->id]);$ids['locations'][]=$location->id;
        $source=$primary?Project::create(['location_id'=>$location->id,'name'=>$key,'status'=>'active']):SecondaryProperty::create(['location_id'=>$location->id,'category_id'=>$base->category_id,'property_code'=>$key,'title'=>$key]);
        $ids[$primary?'projects':'secondary'][]=$source->id;
        $listing=$base->replicate();$listing->fill(['listing_code'=>$key,'listing_type'=>$primary?'primary':'secondary','transaction_type'=>$primary?'sale':'rent','project_id'=>$primary?$source->id:null,'secondary_property_id'=>$primary?null:$source->id,'title'=>'QA FILTER '.$label,'description'=>'Properti sintetis untuk pengujian filter, bukan listing nyata.','price'=>$primary?1200000000:50000000,'price_period'=>$primary?'total':'year','bedrooms'=>3,'bathrooms'=>2,'land_area'=>120,'status'=>'active']);$listing->save();$ids['listings'][]=$listing->id;
        for($j=0;$j<2;$j++){$post=Post::create(['user_id'=>$agent->id,'listing_id'=>$listing->id,'content_type'=>'video','caption'=>$key,'status'=>'published','moderation_status'=>'active','published_at'=>now()->subMinutes(5+$i+$j)]);$ids['posts'][]=$post->id;$post->media()->create(['media_type'=>'video','file_url'=>'qa-only/synthetic-placeholder.mp4','thumbnail_url'=>'qa-only/synthetic-placeholder.jpg','width'=>900,'height'=>1200,'sort_order'=>1]);}
    }
});
file_put_contents($manifest,json_encode($ids,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode(['database'=>'promerty_qa','batam_listings'=>8,'adversarial_listings'=>5,'posts'=>count($ids['posts'])]).PHP_EOL;
