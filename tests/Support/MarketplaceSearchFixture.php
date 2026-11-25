<?php

namespace Tests\Support;

use App\Models\{Listing, Location, Post, Project, PropertyCategory, SecondaryProperty, User};
use Illuminate\Support\Facades\DB;

final class MarketplaceSearchFixture
{
    public static function add(User $agent, User $secondAgent, string $photo, ?int $developer = null, bool $includeTamarama = true): array
    {
        if (!app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite') {
            throw new \LogicException('Marketplace fixtures require the isolated SQLite QA environment.');
        }

        return DB::transaction(function () use ($agent, $secondAgent, $photo, $developer, $includeTamarama) {
            $categories = [];
            foreach (['apartemen'=>'Apartemen', 'rumah'=>'Rumah', 'ruko'=>'Ruko', 'gudang'=>'Gudang'] as $slug=>$name) {
                $categories[$slug] = PropertyCategory::firstOrCreate(['slug'=>$slug], ['name'=>$name])->id;
            }
            $nagoya = Location::create(['label'=>'Nagoya','district'=>'Lubuk Baja','city'=>'Batam','visibility'=>'approximate','address'=>'PRIVATE_SEARCH_FIXTURE_ADDRESS']);
            $harbour = Location::create(['label'=>'Harbour Bay','city'=>'Batam','visibility'=>'approximate']);
            $batam = Location::create(['label'=>'Batam','city'=>'Batam','visibility'=>'approximate']);
            $jakarta = Location::create(['label'=>'Kemang','district'=>'Mampang Prapatan','city'=>'Jakarta','visibility'=>'approximate']);
            $pinang = Location::create(['label'=>'Batu 9','district'=>'Tanjungpinang Timur','city'=>'Tanjung Pinang','visibility'=>'approximate']);
            $riverview = Location::create(['label'=>'Permata Riverview','city'=>'Batam','visibility'=>'approximate']);
            $pollux = Project::create(['name'=>'Pollux Habibie','location_id'=>$batam->id,'developer_id'=>$developer,
                'status'=>'active','created_by'=>$agent->id,'facilities'=>['kolam renang','ruang kebugaran']]);
            $rows = [
                'pollux-rent'=>['Unit studio QA','apartemen','rent',$batam,45000000,$agent,$pollux],
                'pollux-sale'=>['Unit keluarga QA','apartemen','sale',$batam,850000000,$secondAgent,$pollux],
                'pollux-sale-extra'=>['Unit sudut QA','apartemen','sale',$batam,1200000000,$agent,$pollux],
                'nagoya-rent'=>['Rumah halaman teduh QA','rumah','rent',$nagoya,40000000,$agent,null],
                'batam-sale'=>['Rumah taman QA','rumah','sale',$batam,1300000000,$agent,null],
                'ruko-sale'=>['Ruang usaha QA','ruko','sale',$batam,1800000000,$agent,null],
                'gudang-rent'=>['Ruang logistik QA','gudang','rent',$harbour,90000000,$agent,null],
                'riverview-sale'=>['Hunian sudut QA','rumah','sale',$riverview,750000000,$agent,null],
                'jakarta-rent'=>['Studio Kemang QA','apartemen','rent',$jakarta,65000000,$secondAgent,null],
                'pinang-sale'=>['Hunian Batu 9 QA','rumah','sale',$pinang,520000000,$secondAgent,null],
                'draft-rent'=>['Unit draf QA','apartemen','rent',$batam,40000000,$agent,$pollux],
                'archived-rent'=>['Unit arsip QA','apartemen','rent',$batam,50000000,$agent,$pollux],
            ];
            if ($includeTamarama) {
                $project=Project::create(['name'=>'Tamarama Residence','location_id'=>$batam->id,'status'=>'active','created_by'=>$agent->id]);
                $rows['tamarama']=['Gaharu Loft QA','apartemen','sale',$batam,900000000,$agent,$project];
            }
            $posts = [];
            foreach ($rows as $code=>[$title,$slug,$transaction,$location,$price,$owner,$project]) {
                $category=$categories[$slug];
                $property=$project ? null : SecondaryProperty::create(['property_code'=>'QA-MARKET-'.$code,
                    'category_id'=>$category,'title'=>$code==='riverview-sale'?'Permata Riverview':$title,'location_id'=>$location->id]);
                $status=match($code){'draft-rent'=>'draft','archived-rent'=>'archived',default=>'active'};
                $video=match($code){
                    'tamarama'=>'https://www.tiktok.com/@qa_fixture/video/7699394949875371270',
                    'pollux-sale'=>'https://www.instagram.com/reel/QA_Pollux01/',
                    default=>null,
                };
                $listing=Listing::create(['listing_code'=>'QA-MARKET-'.$code,'listing_type'=>$project?'primary':'secondary',
                    'agent_id'=>$owner->id,'branch_id'=>$owner->branch_id??1,'project_id'=>$project?->id,'secondary_property_id'=>$property?->id,
                    'category_id'=>$category,'title'=>$title,'description'=>match ($code) {
                        'batam-sale'=>'Listing sintetis dijual. Referensi sewa apartemen Nagoya untuk uji filter.',
                        'jakarta-rent'=>'Listing sintetis di Jakarta. Penyebutan Pollux Habibie hanya untuk uji pencocokan proyek.',
                        'pollux-sale-extra'=>'Listing sintetis di Pollux. Kata Tamarama di sini hanya untuk uji pencocokan nama proyek.',
                        default=>'Listing sintetis untuk QA.',
                    },
                    'transaction_type'=>$transaction,'price'=>$price,'price_period'=>$transaction==='rent'?'year':'total',
                    'bedrooms'=>1,'bathrooms'=>1,'floors'=>1,'building_area'=>45,
                    'additional_specs'=>$code==='tamarama'?['concept'=>'Modern Mezzanine','balconies'=>2]:null,'status'=>$status]);
                $post=$listing->posts()->create(['user_id'=>$owner->id,'content_type'=>$video?'video':'photo',
                    'status'=>$status==='draft'?'draft':'published','moderation_status'=>'active',
                    'published_at'=>$status==='draft'?null:now()->subDay()]);
                $post->media()->create(['media_type'=>$video?'video':'image','file_url'=>$video??$photo,
                    'thumbnail_url'=>$video?null:$photo,'sort_order'=>1]);
                $posts[$code]=$post;
            }
            return $posts;
        });
    }
}
