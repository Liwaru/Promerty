<?php

namespace Tests\Feature;

use App\Models\{Listing,Location,Post,Project,PropertyCategory,Role,SecondaryProperty,User};
use Tests\Support\FeedDatabase;
use Tests\Support\MarketplaceSearchFixture;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use FeedDatabase;
    private User $agent;
    private Listing $listing;
    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();$this->createFeedDatabase();
        $role=Role::create(['name'=>'agent','level'=>2]);
        $this->agent=User::create(['name'=>'Public Agent','username'=>'public_agent','role_id'=>$role->id,'status'=>'active','email'=>'private@example.test','phone'=>'PRIVATE_PHONE','bio'=>'Property advisor']);
        $category=PropertyCategory::create(['name'=>'Rumah','slug'=>'rumah']);
        $location=Location::create(['label'=>'Batam Center','district'=>'Batam Kota','city'=>'Batam','visibility'=>'approximate','latitude'=>1.2345678,'longitude'=>104.1234567,'public_latitude'=>1.1,'public_longitude'=>104.1,'address'=>'PRIVATE_ADDRESS']);
        $secondary=SecondaryProperty::create(['property_code'=>'SEARCH-P','title'=>'House','category_id'=>$category->id,'location_id'=>$location->id]);
        $this->listing=Listing::create(['listing_code'=>'SEARCH-L','listing_type'=>'secondary','transaction_type'=>'rent','agent_id'=>$this->agent->id,'branch_id'=>1,'category_id'=>$category->id,'secondary_property_id'=>$secondary->id,'title'=>'Rumah taman','description'=>'Hunian tenang 50%_ diskon','price'=>50000000,'price_period'=>'year','bedrooms'=>3,'bathrooms'=>2,'land_area'=>120,'status'=>'active']);
        $this->post=$this->makePost();
    }

    private function makePost(array $attributes=[]): Post
    {
        $post=Post::create([...['user_id'=>$this->agent->id,'listing_id'=>$this->listing->id,'content_type'=>'video','status'=>'published','moderation_status'=>'active','published_at'=>now()->subHour()],...$attributes]);
        $post->media()->create(['media_type'=>$post->content_type==='photo'?'image':'video','file_url'=>'qa-only/tour.mp4','thumbnail_url'=>'qa-only/poster.jpg','sort_order'=>1]);return $post;
    }

    public function test_dedicated_page_and_guest_search_are_public_without_private_fields(): void
    {
        $this->get('/search')->assertOk()->assertSee('id="promerty-search"',false);
        $r=$this->getJson('/api/search?q=Batam')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$this->post->id)->assertJsonMissingPath('data.0.listing.location.address')->assertJsonMissingPath('data.0.agent.email');
        $this->assertStringNotContainsString('PRIVATE_',$r->getContent());$this->assertStringNotContainsString('1.2345678',$r->getContent());
        $this->postJson('/api/posts/'.$this->post->id.'/save')->assertUnauthorized();
    }

    public function test_both_tabs_deduplicate_listing_and_property_prefers_video(): void
    {
        $video=$this->makePost();$this->makePost(['content_type'=>'photo']);
        $video->media()->create(['media_type'=>'image','file_url'=>'qa-only/obsolete.jpg','sort_order'=>0]);
        $this->getJson('/api/search?kind=video')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$video->id);
        $this->getJson('/api/search?kind=property')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$video->id)->assertJsonPath('data.0.media.0.type','video');
    }

    public function test_draft_removed_future_deleted_inactive_or_medialess_content_is_not_public(): void
    {
        $this->makePost(['status'=>'draft']);$this->makePost(['moderation_status'=>'removed']);$this->makePost(['moderation_status'=>'under_review']);$this->makePost(['published_at'=>now()->addDay()]);$this->makePost()->delete();$this->makePost()->media()->delete();
        $this->getJson('/api/search')->assertOk()->assertJsonCount(1,'data');
        $this->listing->update(['status'=>'inactive']);$this->getJson('/api/search')->assertOk()->assertJsonCount(0,'data');
        $this->listing->update(['status'=>'active']);$this->agent->update(['status'=>'suspended']);
        $this->getJson('/api/search')->assertOk()->assertJsonCount(0,'data');$this->getJson('/api/search?kind=agent')->assertJsonCount(0,'data');
    }

    public function test_structured_filters_are_applied_before_pagination(): void
    {
        $filters=['kind'=>'property','q'=>'Hunian','location'=>'Batam Kota','type'=>'secondary','transaction'=>'rent','category'=>$this->listing->category_id,'min_price'=>40000000,'max_price'=>60000000,'price_period'=>'year','bedrooms'=>3,'bathrooms'=>2,'min_area'=>100];
        $this->getJson('/api/search?'.http_build_query($filters))->assertOk()->assertJsonCount(1,'data');
        foreach(['location'=>'Jakarta','type'=>'primary','transaction'=>'sale','price_period'=>'month','bedrooms'=>4,'bathrooms'=>3,'min_area'=>121,'min_price'=>55000000] as $key=>$value){$this->getJson('/api/search?'.http_build_query([...$filters,$key=>$value]))->assertOk()->assertJsonCount(0,'data');}
    }

    public function test_keyword_is_normalized_and_like_wildcards_are_literal(): void
    {
        $this->getJson('/api/search?'.http_build_query(['q'=>'  Rumah   taman  ']))->assertOk()->assertJsonCount(1,'data');
        $this->getJson('/api/search?'.http_build_query(['q'=>'50%_']))->assertOk()->assertJsonCount(1,'data');
        $this->getJson('/api/search?'.http_build_query(['q'=>"%' OR 1=1 --"]))->assertOk()->assertJsonCount(0,'data');
    }

    public function test_agent_results_require_visible_content_and_remain_allowlisted(): void
    {
        User::create(['name'=>'Ghost agent','status'=>'active','role_id'=>$this->agent->role_id]);
        $r=$this->getJson('/api/search?kind=agent&q=advisor')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$this->agent->id)->assertJsonMissingPath('data.0.phone')->assertJsonMissingPath('data.0.role_id');
        $this->assertStringNotContainsString('private@example.test',$r->getContent());
        $named=User::create(['name'=>'Advisor','status'=>'active','role_id'=>$this->agent->role_id]);
        $listing=$this->listing->replicate();$listing->listing_code='SEARCH-ADVISOR';$listing->agent_id=$named->id;$listing->save();
        $namedPost=$this->makePost(['user_id'=>$named->id,'listing_id'=>$listing->id]);
        $this->getJson('/api/search?kind=agent&q=Advisor')->assertOk()->assertJsonPath('data.0.id',$named->id);
        $namedPost->delete();
        $this->post->update(['status'=>'draft']);$this->getJson('/api/search?kind=agent')->assertJsonCount(0,'data');
    }

    public function test_bounded_stable_pagination_and_listing_price_order(): void
    {
        for($i=0;$i<14;$i++)$this->copyListing($i);
        $first=$this->getJson('/api/search?sort=latest')->assertOk()->assertJsonCount(12,'data')->assertJsonPath('next_page',2)->json('data');
        $second=$this->getJson('/api/search?sort=latest&page=2')->assertOk()->assertJsonCount(3,'data')->assertJsonPath('next_page',null)->json('data');
        $this->assertCount(15,array_unique(array_column([...$first,...$second],'id')));
        $other=$this->listing->replicate();$other->listing_code='SEARCH-L2';$other->title='Other';$other->price=10000000;$other->save();$cheap=$this->makePost(['listing_id'=>$other->id]);
        $this->getJson('/api/search?sort=price_asc')->assertJsonPath('data.0.id',$cheap->id);
        $this->getJson('/api/search?kind=property')->assertJsonCount(12,'data');
    }

    public function test_relevance_prioritizes_title_and_validation_bounds_queries(): void
    {
        $other=$this->listing->replicate();$other->listing_code='SEARCH-EXACT';$other->title='Rumah';$other->save();$exact=$this->makePost(['listing_id'=>$other->id]);
        $this->getJson('/api/search?q=Rumah')->assertOk()->assertJsonPath('data.0.id',$exact->id);
        foreach(['page=101','page=-1','min_price=200&max_price=100','kind=all','kind=agent&sort=price_asc','bedrooms=-1','q='.str_repeat('a',121)] as $query)$this->getJson('/api/search?'.$query)->assertUnprocessable();
    }

    private function locatedPost(?string $city, ?string $province=null, string $label='Batam Center', bool $primary=false): Post
    {
        $location=Location::create(['label'=>$label,'city'=>$city,'province'=>$province,'visibility'=>'approximate']);
        $source=$primary?Project::create(['name'=>'Scoped primary','location_id'=>$location->id,'status'=>'active']):SecondaryProperty::create(['property_code'=>'SCOPE-P'.$location->id,'title'=>'Scoped secondary','location_id'=>$location->id,'category_id'=>$this->listing->category_id]);
        $listing=$this->listing->replicate();$listing->fill(['listing_code'=>'SCOPE-L'.$location->id,'listing_type'=>$primary?'primary':'secondary','project_id'=>$primary?$source->id:null,'secondary_property_id'=>$primary?null:$source->id]);$listing->save();
        return $this->makePost(['listing_id'=>$listing->id]);
    }

    public function test_public_locations_are_searchable_without_a_default_city_scope(): void
    {
        $jakarta=$this->locatedPost('Jakarta','DKI Jakarta','Kemang');
        $pinang=$this->locatedPost('Tanjung Pinang','Kepulauan Riau','Batu 9');
        $this->locatedPost(null,null,'Kawasan sintetis');
        $this->getJson('/api/search')->assertOk()->assertJsonCount(4,'data')->assertJsonPath('kind','property');
        foreach (['Jakarta'=>$jakarta,'Tanjung Pinang'=>$pinang,'Batam Center'=>$this->post] as $term=>$post) {
            foreach (['q','location'] as $key) $this->getJson('/api/search?'.http_build_query([$key=>$term]))
                ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$post->id);
        }
        $this->getJson('/api/search?page=2')->assertOk()->assertJsonCount(0,'data');
        foreach (['Unknown district','0',"%\' OR 1=1 --",'Batam%'] as $term) $this->getJson('/api/search?'.http_build_query(['location'=>$term]))
            ->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/search?location[]=Batam')->assertUnprocessable();
    }

    private function copyListing(int $index): void
    {
        $listing=$this->listing->replicate();
        $listing->listing_code='SEARCH-COPY'.$index;
        $listing->save();
        $this->makePost(['listing_id'=>$listing->id,'published_at'=>$this->post->published_at]);
    }

    public function test_batam_areas_are_data_driven_and_alias_is_bidirectional(): void
    {
        foreach(['Nagoya','Bengkong','Tiban','Nongsa','Batu Aji','Baloi','Muka Kuning'] as $i=>$area){
            $post=$this->locatedPost($i%2?'Kota Batam':' BATAM ','Kepulauan Riau',$area,$i%2===0);
            $this->getJson('/api/search?'.http_build_query(['location'=>$area]))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$post->id);
        }
        foreach(['Batam Center','Batam Centre'] as $label){
            $this->listing->secondaryProperty->location->update(['label'=>$label]);
            foreach(['  Batam   Centre  ','Batam Center'] as $query)$this->getJson('/api/search?'.http_build_query(['location'=>$query]))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$this->post->id);
        }
    }

    public function test_opposite_property_relation_cannot_admit_an_outside_listing(): void
    {
        $outside=$this->locatedPost('Jakarta','DKI Jakarta','Nagoya',true);
        $outside->listing->update(['secondary_property_id'=>$this->listing->secondary_property_id]);
        $this->getJson('/api/search?location=Batam')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$this->post->id);
        // Public Feed itself is not re-scoped by this Search-only change.
        $this->getJson('/api/posts/'.$outside->id)->assertOk();
    }

    public function test_agents_can_be_found_through_listings_in_any_city(): void
    {
        $outside=$this->locatedPost('Jakarta','DKI Jakarta');
        $agent=User::create(['name'=>'Outside-only agent','role_id'=>$this->agent->role_id,'status'=>'active']);
        $outside->listing->update(['agent_id'=>$agent->id]);$outside->update(['user_id'=>$agent->id]);
        $this->getJson('/api/search?kind=agent')->assertOk()->assertJsonCount(2,'data');
        $this->getJson('/api/search?kind=agent&location=Jakarta')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$agent->id);
    }

    public function test_smart_combined_queries_apply_every_recognised_attribute_and_keep_unknown_words(): void
    {
        $this->listing->update(['floors'=>2,'building_area'=>90,'additional_specs'=>['concept'=>'hunian mezzanine','balconies'=>'2','internal'=>'PRIVATE_SPEC']]);
        $this->listing->secondaryProperty->location->update(['label'=>'Bengkong']);
        foreach(['sewa rumah 2 lantai di Bengkong','rumah dua lantai bengkong','rumah 2 lt bengkong','rumah 3 kamar 2 kamar mandi','rumah harga 50 juta','rumah harga Rp 50.000.000','rumah minimal 40 jt maksimal 60 jt','rumah 40 juta sampai 60 juta','sewa rumah 50 juta per tahun','rumah luas tanah 120 m2 luas bangunan 90 m2','mezzanine 2 balkon','rumah kontrak','kontrak rumah'] as $q){
            $this->getJson('/api/search?'.http_build_query(['q'=>$q,'kind'=>'property']))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.listing.id',$this->listing->id);
        }
        foreach(['jual rumah 2 lantai bengkong','sewa rumah 3 lantai bengkong','rumah 4 kamar','rumah 3 kamar mandi','rumah harga 1 miliar','rumah harga 50 juta unicorn','rumah tidak dikenal','jual sewa rumah','rumah 60 juta sampai 40 juta','PRIVATE_SPEC','rumah kontrak kerja'] as $q){
            $response=$this->getJson('/api/search?'.http_build_query(['q'=>$q]))->assertOk();
            $this->assertCount(0,$response->json('data'),$q);
        }
        $this->getJson('/api/search?q=di')->assertOk()->assertJsonPath('interpreted.terms.0','di');
        $this->getJson('/api/search?'.http_build_query(['q'=>'sewa rumah 2 lantai','transaction'=>'sale']))->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/search?'.http_build_query(['q'=>'sewa rumah dua lantai Bengkong','kind'=>'agent']))->assertOk()->assertJsonCount(1,'data');
    }

    public function test_smart_project_names_specs_and_aliases_are_searched_across_relations(): void
    {
        $apartment=PropertyCategory::create(['name'=>'Apartemen','slug'=>'apartemen']);
        $post=$this->locatedPost('Batam','Kepulauan Riau','Batam Centre',true);
        $post->listing->update(['category_id'=>$apartment->id,'transaction_type'=>'sale','price'=>1000000000,'price_period'=>'total','floors'=>3]);
        $post->listing->project->update(['name'=>'Pollux Habibie','facilities'=>['kolam renang','gym']]);
        foreach(['apartemen pollux habibie','pollux habibie','jual apartemen batam center','apartemen harga 1 miliar','apartemen gym','apartemen tiga lantai batam centre'] as $q){
            $this->getJson('/api/search?'.http_build_query(['q'=>$q,'kind'=>'property']))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$post->id);
        }
        $post->listing->project->location->update(['city'=>'Jakarta']);
        $this->getJson('/api/search?q=pollux+habibie&location=Jakarta')->assertOk()->assertJsonCount(1,'data');
        $this->getJson('/api/search?q[]=rumah')->assertUnprocessable();
    }

    public function test_smart_queries_preserve_pagination_and_have_bounded_query_count(): void
    {
        $this->listing->update(['floors'=>2]);
        for($i=0;$i<13;$i++)$this->copyListing($i);
        $this->makePost();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $first=$this->getJson('/api/search?q=sewa+rumah+2+lantai&kind=video')->assertOk()->assertJsonCount(12,'data')->assertJsonPath('next_page',2)->json('data');
        $count=count(\Illuminate\Support\Facades\DB::getQueryLog());
        $this->assertLessThanOrEqual(18,$count,'Relationships must be eager loaded, not queried per result.');
        $last=$this->getJson('/api/search?q=sewa+rumah+2+lantai&kind=video&page=2')->assertOk()->assertJsonCount(2,'data')->json('data');
        $this->assertCount(14,array_unique(array_column([...$first,...$last],'id')));
        $this->getJson('/api/search?q=sewa+rumah+2+lantai&kind=property')->assertOk()->assertJsonCount(12,'data');
    }

    public function test_literal_zero_query_does_not_turn_agent_search_into_unfiltered_results(): void
    {
        $this->listing->update(['description'=>'Quiet home']);
        foreach(['video','property','agent'] as $kind)$this->getJson('/api/search?q=0&kind='.$kind)->assertOk()->assertJsonCount(0,'data');
    }

    public function test_marketplace_search_uses_inventory_transaction_location_and_media_metadata(): void
    {
        $second=User::create(['name'=>'QA Second Agent','role_id'=>$this->agent->role_id,'status'=>'active']);
        $posts=MarketplaceSearchFixture::add($this->agent,$second,'synthetic/property.jpg');
        $cases=[
            'sewa apartemen'=>['pollux-rent','jakarta-rent'],'jual apartemen'=>['pollux-sale','pollux-sale-extra','tamarama'],
            'DISEWA APARTMENT'=>['pollux-rent','jakarta-rent'],'apartemen dijual'=>['pollux-sale','pollux-sale-extra','tamarama'],
            'penjualan apartemen'=>['pollux-sale','pollux-sale-extra','tamarama'],'sewa rumah nagoya'=>['nagoya-rent'],
            'jual rumah'=>['batam-sale','riverview-sale','pinang-sale'],
            'jual ruko'=>['ruko-sale'],'sewa gudang'=>['gudang-rent'],'Pollux Habibie'=>['pollux-rent','pollux-sale','pollux-sale-extra'],
            'Permata Riverview'=>['riverview-sale'],'Tamarama Residence'=>['tamarama'],'Gaharu Loft'=>['tamarama'],
            'jual apartemen Tamarama'=>['tamarama'],'jual apartemen mezzanine 2 balkon'=>['tamarama'],
            'sewa apartemen Pollux Habibie'=>['pollux-rent'],'Jakarta'=>['jakarta-rent'],'Tanjung Pinang'=>['pinang-sale'],
        ];
        foreach ($cases as $query=>$keys) {
            $items=$this->getJson('/api/search?'.http_build_query(['q'=>$query]))->assertOk()->json('data');
            $this->assertEqualsCanonicalizing(array_map(fn ($key)=>$posts[$key]->id,$keys),array_column($items,'id'),$query);
        }
        $this->getJson('/api/search?q=jual+apartemen&kind=video')->assertOk()->assertJsonCount(2,'data');
        $pollux=$this->getJson('/api/search?q=Pollux+Habibie&transaction=sale&max_price=1000000000&bedrooms=1&bathrooms=1')
            ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$posts['pollux-sale']->id)
            ->assertJsonPath('data.0.media.0.external_video.provider','instagram');
        $this->assertStringNotContainsString('PRIVATE_SEARCH_FIXTURE_ADDRESS',$pollux->getContent());
        $this->getJson('/api/search?location=Tamarama+Residence&balconies=2&floors=1&min_building_area=45')
            ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.media.0.external_video.provider','tiktok');
        $this->getJson('/api/search?location=Tamarama+Residence&balconies=3')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/search?q=PRIVATE_SEARCH_FIXTURE_ADDRESS')->assertOk()->assertJsonCount(0,'data');
        $posts['tamarama']->listing->update(['orientation'=>'Barat Laut','additional_specs'=>[
            'tower'=>['hidden'=>'privatelegacyvalue'], 'concept'=>str_repeat('x',101).'privateoversizedvalue',
        ]]);
        foreach (['privatelegacyvalue','privateoversizedvalue'] as $term) {
            $this->getJson('/api/search?q='.$term)->assertOk()->assertJsonCount(0,'data');
        }
        $this->getJson('/api/search?q=Barat+Laut')->assertOk()->assertJsonPath('data.0.id',$posts['tamarama']->id);
        $this->listing->update(['title'=>'Pollux Habibie']);
        $this->getJson('/api/search?q=Pollux+Habibie')->assertOk()->assertJsonPath('data.3.id',$this->post->id);
    }
}
