<?php

namespace Tests\Feature;

use App\Models\{AgentProfile, Agency, Branch, Listing, Location, Permission, PropertyCategory, Role, SecondaryProperty, User};
use App\Services\FeedService;
use App\Models\{Post, PostMedia};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage, Schema};
use Laravel\Sanctum\Sanctum;
use Tests\Support\FeedDatabase;
use Tests\TestCase;

class AgentListingDraftTest extends TestCase
{
    use FeedDatabase;
    private User $agent;
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFeedDatabase();
        $role = Role::create(['name' => 'agent', 'level' => 2]);
        foreach (['create_listings', 'manage_own_listings', 'create_posts'] as $name) {
            $role->permissions()->attach(Permission::create(['name' => $name])->id);
        }
        $agency = Agency::create(['name' => 'Synthetic Agency', 'slug' => 'synthetic-agency']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Synthetic Branch', 'status' => 'active']);
        $this->agent = User::create(['name' => 'Synthetic Agent', 'role_id' => $role->id, 'branch_id' => $branch->id, 'status' => 'active']);
        AgentProfile::create(['user_id' => $this->agent->id, 'verification_status' => 'verified']);
        $category = PropertyCategory::create(['name' => 'Rumah', 'slug' => 'rumah']);
        $this->payload = ['title' => 'QA draft — synthetic property', 'category_id' => $category->id,
            'transaction_type' => 'sale', 'price' => '1200000000.00', 'price_period' => 'total', 'location_label' => 'Bengkong'];
    }

    public function test_verified_agent_creates_owned_draft_that_is_not_in_customer_feed(): void
    {
        Sanctum::actingAs($this->agent);
        $id = $this->postJson('/api/agent/listings', $this->payload)->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $listing = Listing::findOrFail($id);
        $this->assertSame($this->agent->id, $listing->agent_id);
        $this->assertSame($this->agent->branch_id, $listing->branch_id);
        $this->assertSame('Batam', $listing->secondaryProperty->location->city);
        $this->assertSame($this->agent->id, $listing->secondaryProperty->created_by);
        $this->assertSame(0, app(FeedService::class)->visible()->count());
        $this->getJson('/api/agent/listings')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.agent_id');
        $other = User::create(['name' => 'Other QA Agent', 'role_id' => $this->agent->role_id,
            'branch_id' => $this->agent->branch_id, 'status' => 'active']);
        AgentProfile::create(['user_id' => $other->id, 'verification_status' => 'verified']);
        Sanctum::actingAs($other);
        $this->getJson('/api/agent/listings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_guest_and_other_roles_cannot_create_agent_drafts(): void
    {
        $this->postJson('/api/agent/listings', $this->payload)->assertUnauthorized();
        foreach (['customer' => 1, 'branch_manager' => 3, 'admin' => 4, 'owner' => 6, 'super_admin' => 5] as $name => $level) {
            $role = Role::create(['name' => $name, 'level' => $level]);
            $user = User::create(['name' => 'Synthetic '.$name, 'role_id' => $role->id, 'status' => 'active']);
            Sanctum::actingAs($user);
            $this->postJson('/api/agent/listings', $this->payload)->assertForbidden();
            $this->getJson('/api/agent/listings')->assertForbidden();
        }
        $this->assertSame(0, Listing::count());
    }

    public function test_video_portfolio_lists_only_owned_local_mp4_in_upload_order_and_filters_by_listing(): void
    {
        Storage::fake('local');
        $this->getJson('/api/agent/videos')->assertUnauthorized();
        Sanctum::actingAs($this->agent);
        $this->getJson('/api/agent/videos')->assertOk()->assertJsonCount(0, 'data');
        $ids = [];
        $urls = [];
        foreach (['draft', 'active', 'archived', 'draft', 'active'] as $index => $status) {
            $id = $this->postJson('/api/agent/listings', [...$this->payload, 'title' => 'Video fixture '.$index])
                ->assertCreated()->json('data.id');
            Listing::whereKey($id)->update(['status' => $status]);
            $post = Post::create(['user_id' => $this->agent->id, 'listing_id' => $id, 'content_type' => 'video',
                'status' => $status === 'active' ? 'published' : 'draft', 'published_at' => $status === 'active' ? now() : null]);
            $name = str_repeat(dechex($index + 1), 48).'.mp4';
            Storage::disk('local')->put('agent-listings/'.$name, str_repeat('synthetic-range-fixture', 8));
            $video = $post->media()->create(['media_type' => 'video', 'file_url' => 'agent-private/'.$name, 'sort_order' => 1]);
            $ids[] = $video->id;
            $urls[] = '/api/listing-media/'.$name;
            $post->media()->create(['media_type' => 'video', 'file_url' => 'https://www.tiktok.com/@fixture/video/1234567', 'sort_order' => 2]);
        }
        $response = $this->getJson('/api/agent/videos')->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.url', $urls[4])->assertJsonPath('data.0.price', 1200000000)
            ->assertJsonMissingPath('data.0.file_url')->assertJsonMissingPath('data.0.agent_id');
        $this->assertSame(array_reverse($ids), array_column($response->json('data'), 'id'));
        $this->getJson('/api/agent/videos?sort=oldest')->assertOk()->assertJsonPath('data.0.id', $ids[0]);
        $this->getJson('/api/agent/videos?status=archived&q=fixture%202')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[2]);
        $this->getJson('/api/agent/videos?status=active&q=fixture%202')->assertOk()->assertJsonCount(0, 'data');
        $this->get($urls[0], ['Range' => 'bytes=0-31'])->assertStatus(206);
        $this->get($urls[2], ['Range' => 'bytes=0-31'])->assertStatus(206);

        $other = User::create(['name' => 'Other QA Agent', 'role_id' => $this->agent->role_id,
            'branch_id' => $this->agent->branch_id, 'status' => 'active']);
        AgentProfile::create(['user_id' => $other->id, 'verification_status' => 'verified']);
        Sanctum::actingAs($other);
        $this->getJson('/api/agent/videos')->assertOk()->assertJsonCount(0, 'data');
        $this->get($urls[0])->assertNotFound();
        $this->get($urls[2])->assertNotFound();
    }

    public function test_unverified_suspended_missing_permission_and_inactive_branch_are_rejected(): void
    {
        Sanctum::actingAs($this->agent);
        $this->agent->agentProfile->update(['verification_status' => 'pending']);
        $this->postJson('/api/agent/listings', $this->payload)->assertForbidden();
        $this->agent->agentProfile->update(['verification_status' => 'verified']);
        $this->agent->update(['status' => 'suspended']);
        $this->postJson('/api/agent/listings', $this->payload)->assertForbidden();
        $this->agent->update(['status' => 'active']);
        $this->agent->role->permissions()->detach();
        $this->postJson('/api/agent/listings', $this->payload)->assertForbidden();
        $this->agent->role->permissions()->attach(Permission::where('name', 'create_listings')->firstOrFail()->id);
        $this->agent->branch->update(['status' => 'inactive']);
        $this->postJson('/api/agent/listings', $this->payload)->assertForbidden();
        $this->assertSame(0, Listing::count());
    }

    public function test_client_cannot_assign_ownership_or_publish_and_invalid_data_does_not_create_partial_records(): void
    {
        Sanctum::actingAs($this->agent);
        foreach (['agent_id' => 999, 'branch_id' => 999, 'status' => 'active', 'project_id' => 1,
            'secondary_property_id' => 1, 'listing_type' => 'unsupported', 'moderation_status' => 'active'] as $key => $value) {
            $this->postJson('/api/agent/listings', [...$this->payload, $key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        foreach ([['price' => '-1'], ['price' => '2.001'], ['price' => '10000000000000000.00'],
            ['location_label' => str_repeat('a', 181)], ['price_period' => 'month'], ['category_id' => 999], ['title' => '']] as $bad) {
            $this->postJson('/api/agent/listings', [...$this->payload, ...$bad])->assertUnprocessable();
        }
        $this->assertSame(0, Listing::count());
        $this->assertSame(0, Location::count());
        $this->assertSame(0, SecondaryProperty::count());
    }

    private function draft(): int
    {
        Sanctum::actingAs($this->agent);
        Storage::fake('local');
        return $this->postJson('/api/agent/listings',$this->payload)->assertCreated()->json('data.id');
    }

    public function test_repeating_a_draft_request_reuses_the_listing_and_inventory(): void
    {
        Sanctum::actingAs($this->agent);
        $headers = ['Idempotency-Key' => 'synthetic-draft-replay'];
        $id = $this->postJson('/api/agent/listings', $this->payload, $headers)->assertCreated()->json('data.id');
        $this->postJson('/api/agent/listings', [...$this->payload, 'title'=>'New unsaved edits'], $headers)
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('replayed', true)
            ->assertJsonPath('data.title',$this->payload['title']);
        $this->assertSame(1, Listing::count());
        $this->assertSame(1, SecondaryProperty::count());
        $this->assertSame(1, Location::count());
    }

    public function test_external_video_uses_the_owned_listing_publication_lifecycle(): void
    {
        $id = $this->draft();
        $endpoint = "/api/agent/listings/{$id}/media-link";
        $url = 'https://www.tiktok.com/@provider_test/video/7699394949875371270';
        foreach (['http://www.tiktok.com/@a/video/7699394949875371270', 'https://www.tiktok.com.evil.test/@a/video/7699394949875371270',
            'https://user@www.tiktok.com/@a/video/7699394949875371270', 'https://127.0.0.1/reel/abcde/',
            'https://www.instagram.com/p/abcde/', 'https://www.tiktok.com/@a/video/%377/',
            'https://www.instagram.com:443/reel/abcde/'] as $bad) {
            $this->postJson($endpoint, ['url' => $bad])->assertUnprocessable()->assertJsonValidationErrors('url');
        }
        $this->postJson($endpoint, ['url' => $url, 'provider' => 'instagram'])->assertUnprocessable();
        $mediaId = $this->postJson($endpoint, ['url' => $url.'?tracking=1#caption'])->assertCreated()->json('data.id');
        $this->postJson($endpoint, ['url' => $url])->assertOk()->assertJsonPath('data.id', $mediaId);
        $this->postJson($endpoint, ['url' => 'https://www.instagram.com/reel/AB_cd10/'])->assertUnprocessable();
        $this->assertDatabaseHas('post_media', ['id' => $mediaId, 'file_url' => $url, 'file_size_bytes' => null]);
        $this->getJson('/api/feed')->assertJsonCount(0, 'data');
        $this->postJson("/api/agent/listings/{$id}/publish")->assertOk();
        $this->getJson('/api/feed')->assertOk()->assertJsonPath('data.0.media.0.external_video.id', '7699394949875371270');
        $permission = Permission::create(['name'=>'interact_posts']);
        $this->agent->role->permissions()->attach($permission);
        \App\Models\AppSetting::create(['setting_key'=>'allow_video_download','setting_value'=>'true','data_type'=>'boolean']);
        $publicPost = Post::where('listing_id', $id)->firstOrFail();
        $this->postJson("/api/posts/{$publicPost->id}/downloads", ['media_ids'=>[$mediaId]])->assertUnprocessable();
        $this->assertDatabaseCount('download_sessions', 0);
        $this->postJson("/api/agent/listings/{$id}/archive")->assertOk();
        $this->getJson('/api/feed')->assertJsonCount(0, 'data');
        $this->deleteJson("/api/agent/listings/{$id}/media/{$mediaId}")->assertOk()->assertJsonPath('file_removed', false);
        $this->postJson($endpoint, ['url' => 'https://instagram.com/reel/AB_cd10?source=qa'])->assertCreated();
        $this->getJson("/api/agent/listings/{$id}")->assertOk()->assertJsonPath('data.media.0.external_video.url','https://www.instagram.com/reel/AB_cd10/');
        $this->postJson("/api/agent/listings/{$id}/publish")->assertOk();
        $post = Post::where('listing_id', $id)->firstOrFail();
        $this->getJson("/api/posts/{$post->id}")->assertOk()->assertJsonPath('data.media.0.external_video.provider', 'instagram');
        $this->getJson('/api/search?q=Bengkong')->assertOk()->assertJsonPath('data.0.media.0.external_video.id', 'AB_cd10');
    }
    public function test_two_verified_agents_publish_once_into_the_same_guest_feed(): void
    {
        $second = User::create(['name'=>'Second synthetic Agent','role_id'=>$this->agent->role_id,
            'branch_id'=>$this->agent->branch_id,'status'=>'active']);
        AgentProfile::create(['user_id'=>$second->id,'verification_status'=>'verified']);
        $ids = [];
        foreach ([
            [$this->agent,'QA First Inventory','https://www.tiktok.com/@provider_test/video/7699394949875371270'],
            [$second,'QA Second Inventory','https://www.instagram.com/reel/AB_cd10/'],
        ] as [$agent,$title,$url]) {
            Sanctum::actingAs($agent);
            $id = $this->postJson('/api/agent/listings', [...$this->payload,'title'=>$title])->assertCreated()->json('data.id');
            $ids[] = $id;
            $base = "/api/agent/listings/$id";
            $this->postJson("$base/media-link", ['url'=>$url])->assertCreated();
            $this->postJson("$base/publish")->assertOk();
            $this->postJson("$base/publish")->assertOk();
        }
        $this->app['auth']->forgetGuards();
        $guest = $this->getJson('/api/feed')->assertOk()->assertJsonCount(2,'data')->json('data');
        $this->assertEqualsCanonicalizing($ids, array_column(array_column($guest,'listing'),'id'));
        $this->assertEqualsCanonicalizing([$this->agent->id,$second->id], array_column(array_column($guest,'agent'),'id'));
        $this->assertDatabaseCount('posts', 2);
        $this->getJson('/api/search?kind=video&q=QA+Second+Inventory')->assertOk()->assertJsonCount(1,'data');
        $post = Post::where('listing_id',$ids[1])->sole();
        $this->getJson("/api/posts/$post->id")->assertOk()->assertJsonPath('data.agent.id',$second->id);
        Sanctum::actingAs($second);
        $this->postJson("/api/agent/listings/{$ids[1]}/archive")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/feed')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.listing.id',$ids[0]);
        $this->getJson("/api/posts/$post->id")->assertNotFound();
    }

    private function image(int $id): PostMedia
    {
        $media=$this->post('/api/agent/listings/'.$id.'/media',['media'=>UploadedFile::fake()->image('property.png',64,96)],['Accept'=>'application/json'])->assertCreated()->json('data.id');
        return PostMedia::findOrFail($media);
    }

    public function test_local_mp4_lifecycle_connects_agent_customer_and_public_routes(): void
    {
        $id = $this->draft();
        $listing = "/api/agent/listings/$id";
        $title = 'QA Synthetic Apartment Tour';
        $this->patchJson($listing, [...$this->payload, 'title'=>$title, 'price'=>'900000000',
            'additional_specs'=>['allow_video_download'=>true]])->assertOk()->assertJsonPath('data.title', $title);

        // Container metadata for HTTP tests. Actual frame decoding is covered in browser QA.
        $box = fn (string $name, string $body) => pack('N', strlen($body)+8).$name.$body;
        $tkhd = str_repeat("\0",40).pack('N*',65536,0,0,0,65536,0,0,0,1073741824,720*65536,1280*65536);
        $stsd = pack('N*',0,1).$box('avc1',str_repeat("\0",78).$box('avcC',"\1".str_repeat("\0",6)));
        $stbl = $box('stsd',$stsd).$box('stsz',pack('N*',0,4,1)).$box('stco',pack('N*',0,1,24))
            .$box('stsc',pack('N*',0,1,1,1,1)).$box('stts',pack('N*',0,1,1,2000));
        $mdia = $box('hdlr',str_repeat("\0",8).'vide').$box('mdhd',pack('N*',0,0,0,1000,2000)).$box('minf',$box('stbl',$stbl));
        $bytes = $box('ftyp','isom'.pack('N',0)).$box('mdat','TEST').$box('moov',$box('trak',$box('tkhd',$tkhd).$box('mdia',$mdia)));
        $upload = UploadedFile::fake()->createWithContent('tour.mp4', $bytes)->mimeType('video/mp4');
        fflush($upload->tempFile);
        $mediaId = $this->post("$listing/media", ['media'=>$upload], ['Accept'=>'application/json'])
            ->assertCreated()->json('data.id');
        $media = PostMedia::findOrFail($mediaId);
        $post = "/api/posts/$media->post_id";
        $stream = '/api/listing-media/'.basename($media->file_url);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/feed')->assertJsonCount(0,'data');
        $this->get($stream)->assertNotFound();
        $this->getJson($post)->assertNotFound();

        Sanctum::actingAs($this->agent);
        $this->postJson("$listing/publish")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/feed')->assertJsonPath('data.0.listing.id', $id)->assertJsonPath('data.0.media.0.url', $stream);
        $search = '/api/search?kind=video&q='.urlencode($title);
        $this->getJson($search)->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$media->post_id);
        $this->getJson($post)->assertJsonPath('data.listing.title',$title)->assertJsonPath('data.listing.price','900000000.00');
        $this->get($stream, ['Range'=>'bytes=0-15'])->assertStatus(206);
        $this->postJson("$post/like")->assertUnauthorized();
        $this->postJson("$post/comments", ['content'=>'Guest'])->assertUnauthorized();
        $this->postJson("$post/save")->assertUnauthorized();
        $follow = "/api/agents/{$this->agent->id}/follow";
        $this->postJson($follow)->assertUnauthorized();
        \App\Models\AppSetting::create(['setting_key'=>'allow_video_download','setting_value'=>'true','data_type'=>'boolean']);
        $signed = $this->postJson("$post/downloads", ['media_ids'=>[$mediaId]])->assertCreated()->json('files.0.url');
        $response = $this->get($signed)->assertOk()->assertDownload('Promerty-Qa-Synthetic-Apartment-Tour.mp4');
        $this->assertSame(hash('sha256',$bytes),hash_file('sha256',$response->baseResponse->getFile()->getPathname()));

        $role = Role::create(['name'=>'customer','level'=>1]);
        $role->permissions()->attach(Permission::create(['name'=>'interact_posts'])->id);
        $customer = User::create(['name'=>'Synthetic Customer','role_id'=>$role->id,'status'=>'active']);
        Sanctum::actingAs($customer);
        $this->postJson("$post/like")->assertOk()->assertJsonPath('count',1);
        $this->postJson("$post/save")->assertOk()->assertJsonPath('count',1);
        $this->postJson("$post/comments",['content'=>'QA comment'])->assertCreated();
        $this->postJson($follow)->assertOk();
        $this->postJson($follow)->assertOk();
        $this->assertDatabaseCount('follows',1);
        $this->getJson('/api/feed/following')->assertJsonCount(1,'data');
        $this->getJson('/api/feed?saved=1')->assertJsonCount(1,'data');
        $this->getJson($post)->assertJsonPath('data.counts.comments',1)->assertJsonPath('data.viewer.liked',true);
        $this->deleteJson($follow)->assertOk()->assertJsonPath('active',false);
        $this->deleteJson("$post/save")->assertOk()->assertJsonPath('active',false);
        $this->getJson('/api/feed?saved=1')->assertJsonCount(0,'data');

        Sanctum::actingAs($this->agent);
        $this->postJson("$listing/archive")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/feed')->assertJsonCount(0,'data');
        $this->getJson($search)->assertJsonCount(0,'data');
        $this->get($stream)->assertNotFound();
        $this->get($signed)->assertNotFound();
        Sanctum::actingAs($this->agent);
        $this->postJson("$listing/publish")->assertOk();
        $this->postJson("$listing/publish")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/feed')->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$media->post_id);
        $this->assertDatabaseCount('listings',1);
        $this->assertDatabaseCount('posts',1);
        $this->assertDatabaseCount('post_media',1);
    }

    public function test_thirty_photos_are_allowed_and_the_next_upload_writes_nothing(): void
    {
        $id=$this->draft();
        for ($i=0;$i<30;$i++) $last=$this->image($id);
        $this->post('/api/agent/listings/'.$id.'/media',['media'=>UploadedFile::fake()->image('extra.png')],
            ['Accept'=>'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('post_media',30);
        $this->assertCount(30,Storage::disk('local')->allFiles('agent-listings'));
        $ids=PostMedia::orderByDesc('id')->pluck('id')->all();
        $this->patchJson('/api/agent/listings/'.$id.'/media-order',['media_ids'=>$ids])->assertOk();
        $this->deleteJson("/api/agent/listings/$id/media/".$last->id)->assertOk();
        $this->image($id);
        $this->assertDatabaseCount('post_media',30);
    }

    public function test_video_size_limits_resource_gate_and_disabled_feature(): void
    {
        $id=$this->draft();
        $url="/api/agent/listings/$id/video-upload";
        $input=['sha256'=>str_repeat('a',64),'size'=>750000000];
        $this->postJson($url,$input)->assertNotFound();
        config(['agent.resumable_video'=>true]);
        $this->postJson($url,[...$input,'size'=>750000001])->assertUnprocessable();
        $this->postJson($url,$input)->assertOk()->assertJsonPath('offset',0);
        $this->postJson($url,[...$input,'sha256'=>str_repeat('b',64)])->assertConflict();
        $upload=$this->postJson($url,$input)->assertOk()->json('id');
        $this->deleteJson("$url/$upload")->assertNoContent();
        config(['agent.video_max_bytes'=>500000000]);
        $this->postJson($url,[...$input,'size'=>500000001])->assertUnprocessable();
        config(['agent.video_disk_reserve_bytes'=>PHP_INT_MAX]);
        $this->postJson($url,[...$input,'size'=>500000000])->assertStatus(507);
        config(['agent.video_disk_reserve_bytes'=>0]);
        $this->postJson($url,[...$input,'size'=>500000000])->assertOk();
        $this->assertDatabaseCount('post_media',0);
    }

    public function test_video_chunks_resume_idempotently_and_wrong_final_hash_cannot_publish(): void
    {
        $id=$this->draft();
        config(['agent.resumable_video'=>true,'agent.video_chunk_bytes'=>1024,'agent.video_disk_reserve_bytes'=>0]);
        $url="/api/agent/listings/$id/video-upload";
        $input=['sha256'=>str_repeat('a',64),'size'=>1025];
        $upload=$this->postJson($url,$input)->assertOk()->json('id');
        $chunk="$url/$upload/chunk";
        $append=fn ($offset,$bytes,$sha=null) => $this->post($chunk,[
            'offset'=>$offset,'sha256'=>$sha??hash('sha256',$bytes),'chunk'=>UploadedFile::fake()->createWithContent('part.bin',$bytes)
        ],['Accept'=>'application/json']);
        $append(1024,'v')->assertConflict();
        $append(0,str_repeat('u',1024),str_repeat('a',64))->assertUnprocessable();
        $append(0,str_repeat('u',1024))->assertOk()->assertJsonPath('offset',1024);
        $append(0,str_repeat('u',1024))->assertOk()->assertJsonPath('offset',1024);
        $append(0,str_repeat('x',1024))->assertConflict();
        $this->postJson($url,$input)->assertOk()->assertJsonPath('offset',1024);
        $this->postJson("$url/$upload/finish")->assertConflict();
        $other=User::create(['name'=>'Another Agent','role_id'=>$this->agent->role_id,
            'branch_id'=>$this->agent->branch_id,'status'=>'active']);
        AgentProfile::create(['user_id'=>$other->id,'verification_status'=>'verified']);
        Sanctum::actingAs($other);
        $append(1024,'v')->assertNotFound();
        Sanctum::actingAs($this->agent);
        $append(1024,'v')->assertOk()->assertJsonPath('offset',1025);
        $this->postJson("$url/$upload/finish")->assertUnprocessable();
        $this->assertDatabaseCount('post_media',0);
        $this->deleteJson("$url/$upload")->assertNoContent();
        $this->postJson($url,$input)->assertOk()->assertJsonPath('offset',0);
    }

    public function test_video_staging_lock_refuses_concurrent_registry_mutation(): void
    {
        $id=$this->draft();
        config(['agent.resumable_video'=>true,'agent.video_disk_reserve_bytes'=>0]);
        $disk=Storage::disk('local');
        $disk->makeDirectory('agent-video-staging');
        $lock=fopen($disk->path('agent-video-staging/registry.lock'),'c+b');
        try {
            $this->assertTrue(flock($lock,LOCK_EX));
            $this->postJson("/api/agent/listings/$id/video-upload",['size'=>1000000,'sha256'=>str_repeat('a',64)])
                ->assertStatus(423);
            $this->assertDatabaseCount('post_media',0);
            $this->assertCount(1,$disk->allFiles('agent-video-staging'));
        } finally { fclose($lock); }
    }

    public function test_lossless_optimization_keeps_original_and_removes_only_terminal_padding(): void
    {
        Storage::fake('local');
        $disk=Storage::disk('local');
        $box=fn ($type,$bytes)=>pack('N',strlen($bytes)+8).$type.$bytes;
        $ftyp=$box('ftyp','isom'.pack('N',0));
        $mdat=$box('mdat','TEST');
        $stsd=pack('N*',0,1).$box('avc1',str_repeat("\0",78).$box('avcC',"\1".str_repeat("\0",6)));
        $stbl=$box('stsd',$stsd).$box('stsz',pack('N*',0,4,1)).$box('stco',pack('N*',0,1,24))
            .$box('stsc',pack('N*',0,1,1,1,1)).$box('stts',pack('N*',0,1,1,2000));
        $track=$box('tkhd',str_repeat("\0",40).pack('N*',65536,0,0,0,65536,0,0,0,1073741824,720*65536,1280*65536));
        $mdia=$box('hdlr',str_repeat("\0",8).'vide').$box('mdhd',pack('N*',0,0,0,1000,2000)).$box('minf',$box('stbl',$stbl));
        $plain=$ftyp.$mdat.$box('moov',$box('trak',$track.$box('mdia',$mdia)));
        $padded=$plain.$box('free',str_repeat("\0",128));
        $disk->put('plain.mp4',$plain);
        $disk->put('padded.mp4',$padded);
        config(['agent.video_disk_reserve_bytes'=>0]);
        $optimizer=app(\App\Services\LightMp4Optimization::class);
        $this->assertSame('original',$optimizer->trim($disk->path('plain.mp4'),fn () => true)['outcome']);
        $disk->assertMissing('plain.mp4.playback');
        $this->assertSame('optimized',$optimizer->trim($disk->path('padded.mp4'),fn () => true)['outcome']);
        $this->assertSame($plain,$disk->get('padded.mp4.playback'));
        $this->assertSame($padded,$disk->get('padded.mp4'));
        $disk->put('removed.mp4',$padded);
        try {
            $optimizer->trim($disk->path('removed.mp4'),fn () => false);
            $this->fail('An unconfirmed optimization was served.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Candidate no longer valid.',$error->getMessage());
            $disk->assertMissing('removed.mp4.playback');
            $this->assertSame($padded,$disk->get('removed.mp4'));
        }
    }
    public function test_edit_private_upload_publish_search_detail_and_archive_use_actual_customer_queries(): void
    {
        $id=$this->draft();
        $this->patchJson('/api/agent/listings/'.$id,[...$this->payload,'title'=>'Synthetic Bengkong House',
            'description'=>'Synthetic listing, not a claim about a real property.','address'=>'PRIVATE QA ADDRESS',
            'additional_specs'=>['carport'=>2,'tower'=>'QA Tower']])->assertOk();
        $media=$this->image($id);$url=\App\Services\FeedMedia::url($media->file_url);
        $this->get($url)->assertOk()->assertHeader('Content-Type','image/png');
        $this->assertFileDoesNotExist(public_path('storage/agent-listings/'.basename($media->file_url)));
        $this->getJson('/api/feed')->assertJsonCount(0,'data');
        $first=$this->postJson('/api/agent/listings/'.$id.'/publish')->assertOk()->json('data');
        $post=Post::firstOrFail();$time=$post->published_at->toIso8601String();
        $this->postJson('/api/agent/listings/'.$id.'/publish')->assertOk();
        $this->assertSame(1,Post::count());$this->assertSame($time,$post->fresh()->published_at->toIso8601String());
        $this->assertSame('/posts/'.$post->id,$first['public_url']);
        $this->patchJson('/api/agent/listings/'.$id,$this->payload)->assertStatus(409);
        $this->deleteJson('/api/agent/listings/'.$id.'/media/'.$media->id)->assertStatus(409);
        $this->getJson('/api/feed')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.listing.id',$id)
            ->assertJsonMissingPath('data.0.listing.location.address');
        $search=$this->getJson('/api/search?kind=property&q=Bengkong')->assertOk()->assertJsonCount(1,'data');
        $this->assertStringNotContainsString('PRIVATE QA ADDRESS',$search->getContent());
        $this->getJson('/api/posts/'.$post->id)->assertOk()->assertJsonPath('data.agent.id',$this->agent->id);
        $this->getJson('/api/agent/listings')->assertJsonPath('summary.published',1)->assertJsonPath('summary.draft',0);
        $this->postJson('/api/agent/listings/'.$id.'/archive')->assertOk();
        $this->postJson('/api/agent/listings/'.$id.'/archive')->assertOk();
        $this->getJson('/api/feed')->assertJsonCount(0,'data');
        $this->getJson('/api/search?kind=property&q=Bengkong')->assertJsonCount(0,'data');
        $this->getJson('/api/posts/'.$post->id)->assertNotFound();
        $this->getJson('/api/agent/listings')->assertJsonPath('summary.archived',1);
        $this->patchJson('/api/agent/listings/'.$id,$this->payload)->assertOk()->assertJsonPath('data.status','draft');
        $this->assertSame(1,Listing::count());$this->assertSame(1,Location::count());$this->assertSame(1,SecondaryProperty::count());
    }
    public function test_other_agent_cannot_read_edit_publish_archive_or_fetch_private_media(): void
    {
        $id=$this->draft();$media=$this->image($id);
        $other=User::create(['name'=>'Other synthetic Agent','role_id'=>$this->agent->role_id,'branch_id'=>$this->agent->branch_id,'status'=>'active']);
        AgentProfile::create(['user_id'=>$other->id,'verification_status'=>'verified']);Sanctum::actingAs($other);
        $this->getJson('/api/agent/listings/'.$id)->assertNotFound();
        $this->patchJson('/api/agent/listings/'.$id,$this->payload)->assertNotFound();
        foreach(['publish','archive'] as $action)$this->postJson('/api/agent/listings/'.$id.'/'.$action)->assertNotFound();
        $this->post('/api/agent/listings/'.$id.'/media',['media'=>UploadedFile::fake()->image('other.png')],['Accept'=>'application/json'])->assertNotFound();
        $this->deleteJson('/api/agent/listings/'.$id.'/media/'.$media->id)->assertNotFound();
        $this->get(\App\Services\FeedMedia::url($media->file_url))->assertNotFound();
        $this->assertSame('draft',Listing::find($id)->status);$this->assertSame(1,PostMedia::count());
    }
    public function test_publication_without_media_or_with_moderation_hold_is_rejected_without_partial_state(): void
    {
        $id=$this->draft();$this->postJson('/api/agent/listings/'.$id.'/publish')->assertUnprocessable();
        $this->assertSame(0,Post::count());$this->assertSame('draft',Listing::find($id)->status);
        $this->image($id);Post::firstOrFail()->update(['moderation_status'=>'removed']);
        $this->postJson('/api/agent/listings/'.$id.'/publish')->assertStatus(409);
        $this->assertSame('draft',Listing::find($id)->status);
    }
    public function test_media_remove_is_owned_and_damaged_spoofed_oversized_files_are_rejected(): void
    {
        $id=$this->draft();
        foreach ([UploadedFile::fake()->createWithContent('fake.jpg','<?php echo "not an image";'),
            UploadedFile::fake()->createWithContent('broken.mp4',pack('N',24).'ftypisom'.str_repeat("\0",12)),
            UploadedFile::fake()->image('oversized.png')->size(config('agent.image_max_kb')+1)] as $file) {
            $this->post('/api/agent/listings/'.$id.'/media',['media'=>$file],['Accept'=>'application/json'])->assertUnprocessable();
        }
        $this->assertSame(0,PostMedia::count());$this->assertSame(0,Post::count());
        $one=$this->image($id);$two=$this->image($id);
        $this->deleteJson('/api/agent/listings/'.$id.'/media/'.$one->id)->assertOk()->assertJsonPath('file_removed',true);
        Storage::disk('local')->assertMissing(\App\Services\AgentMedia::path($one));
        Storage::disk('local')->assertExists(\App\Services\AgentMedia::path($two));
        $this->assertSame(1,PostMedia::count());
    }
    public function test_upload_database_failure_rolls_back_records_and_removes_only_new_file(): void
    {
        $id=$this->draft();$old=$this->image($id);
        DB::statement("CREATE TRIGGER qa_reject_media BEFORE INSERT ON post_media BEGIN SELECT RAISE(ABORT, 'Synthetic media write failure'); END");
        $this->post('/api/agent/listings/'.$id.'/media',['media'=>UploadedFile::fake()->image('failure.png')],['Accept'=>'application/json'])->assertStatus(500);
        $this->assertSame(1,PostMedia::count());
        $this->assertCount(1,Storage::disk('local')->allFiles('agent-listings'));
        Storage::disk('local')->assertExists(\App\Services\AgentMedia::path($old));
    }
    public function test_media_collection_outside_its_expected_private_directory_is_rejected(): void
    {
        $id = $this->draft();
        $media = $this->image($id);
        $disk = Storage::disk('local');
        $disk->makeDirectory('misconfigured-collection');
        $adapter = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $adapter->shouldReceive('path')->with('')->andReturn($disk->path(''));
        $adapter->shouldReceive('path')->with('agent-listings')->andReturn($disk->path('misconfigured-collection'));
        $adapter->shouldReceive('makeDirectory')->with('agent-listings')->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($adapter);

        $this->assertFalse(\App\Services\AgentMedia::exists($media));
        $this->get(\App\Services\FeedMedia::url($media->file_url))->assertNotFound();
        $this->post('/api/agent/listings/'.$id.'/media', ['media'=>UploadedFile::fake()->image('new.png')],
            ['Accept'=>'application/json'])->assertUnprocessable();
        $this->assertSame([], $disk->allFiles('misconfigured-collection'));
        $disk->assertExists(\App\Services\AgentMedia::path($media));
        $this->assertSame(1,PostMedia::count());
    }
    public function test_changed_branch_and_shared_inventory_cannot_be_modified(): void
    {
        $id=$this->draft();$property=Listing::findOrFail($id)->secondaryProperty;
        Listing::create([...$this->payload,'listing_code'=>'SHARED-QA','listing_type'=>'secondary','agent_id'=>$this->agent->id,
            'branch_id'=>$this->agent->branch_id,'secondary_property_id'=>$property->id,'status'=>'draft']);
        $this->patchJson('/api/agent/listings/'.$id,[...$this->payload,'title'=>'Must not replace shared'])->assertStatus(409);
        $this->assertSame($this->payload['title'],$property->fresh()->title);
        $this->agent->update(['branch_id'=>999]);
        $this->getJson('/api/agent/listings/'.$id)->assertForbidden();
    }
    public function test_web_dashboard_requires_real_session_and_existing_role_guards(): void
    {
        $this->get('/api/agent/listings')->assertUnauthorized()->assertHeader('Content-Type','application/json');
        $this->get('/agent')->assertRedirect('/?panel=profile');
        $this->agent->update(['email'=>'agent-fixture@example.test','password'=>'fixture-password']);
        $this->postJson('/web-auth/login',['email'=>$this->agent->email,'password'=>'fixture-password'])->assertOk()
            ->assertJsonPath('redirect_to','/agent')->assertJsonPath('dashboard_available',true);
        $this->get('/agent')->assertOk()->assertSee('Statistik performa')->assertDontSee('agent-listing-form')->assertSee('Lingkungan pengujian');
        $this->get('/agent/listings/create')->assertOk()->assertSee('agent-listing-form', false);
        $this->postJson('/web-auth/logout')->assertOk();$this->get('/agent')->assertRedirect('/?panel=profile');
    }
    public function test_workspace_routes_render_distinct_sections_and_keep_agent_guards(): void
    {
        (require database_path('migrations/2026_11_20_120000_create_project_assignments_table.php'))->up();
        $routes = ['/agent' => 'overview', '/agent/listings' => 'listings', '/agent/listings/create' => 'create',
            '/agent/projects' => 'projects', '/agent/media' => 'media'];
        foreach (array_keys($routes) as $url) {
            $this->get($url)->assertRedirect('/?panel=profile');
        }
        $customer = User::create(['name' => 'Customer QA',
            'role_id' => Role::create(['name' => 'customer', 'level' => 1])->id, 'status' => 'active']);
        foreach (array_keys($routes) as $url) {
            $this->actingAs($customer, 'web')->get($url)->assertForbidden();
        }
        foreach ($routes as $url => $section) {
            $this->actingAs($this->agent, 'web')->get($url)->assertOk()
                ->assertSee('data-section="'.$section.'"', false)->assertSee('aria-current="page"', false);
        }
        $this->get('/agent/listings?view=video')->assertOk()->assertSee('id="agent-video-grid"', false)
            ->assertDontSee('id="agent-listings"', false);
    }

    public function test_public_agent_portfolio_filters_media_deduplicates_listings_and_protects_private_content(): void
    {
        $this->agent->update(['email' => 'agent-private@example.test', 'bio' => 'Prospektus properti QA',
            'username' => 'dev-local-agent-a1b2c3']);
        Sanctum::actingAs($this->agent);
        foreach ([
            ['Published video', 'active', 'video', 'published', 'active'],
            ['Published photo', 'active', 'photo', 'published', 'active'],
            ['Draft portfolio', 'draft', 'video', 'draft', 'active'],
            ['Archived portfolio', 'archived', 'photo', 'published', 'active'],
            ['Moderated portfolio', 'active', 'video', 'published', 'hidden'],
        ] as [$title, $status, $type, $postStatus, $moderation]) {
            $id = $this->postJson('/api/agent/listings', [...$this->payload, 'title' => $title])->assertCreated()->json('data.id');
            Listing::whereKey($id)->update(['status' => $status]);
            $posts = $title === 'Published video' ? 2 : 1;
            for ($index = 0; $index < $posts; $index++) {
                $post = Post::create(['user_id' => $this->agent->id, 'listing_id' => $id, 'content_type' => $type,
                    'status' => $postStatus, 'published_at' => now()->subMinute(), 'moderation_status' => $moderation]);
                $post->media()->create(['media_type' => $type === 'photo' ? 'image' : 'video', 'sort_order' => 1,
                    'file_url' => $type === 'photo' ? 'fixtures/portfolio.jpg' : 'https://www.tiktok.com/@fixture/video/1234567']);
            }
        }
        $url = '/agents/'.$this->agent->id;
        $page = $this->get($url)->assertOk()->assertSee('Prospektus properti QA')->assertSee('Agent terverifikasi')
            ->assertSee('Published video')->assertSee('Published photo')->assertDontSee('Draft portfolio')
            ->assertDontSee('Archived portfolio')->assertDontSee('Moderated portfolio')->assertDontSee($this->agent->email)
            ->assertDontSee('Keamanan Akun')->assertDontSee('Edit Profil')->assertDontSee('dev-local-agent-a1b2c3');
        $this->assertSame(1, substr_count($page->getContent(), 'Published video'));
        $this->get($url.'?media=video')->assertOk()->assertSee('Published video')->assertDontSee('Published photo');
        $this->get($url.'?media=photo')->assertOk()->assertSee('Published photo')->assertDontSee('Published video');
        $this->agent->update(['username' => 'prospektus.qa']);
        $this->actingAs($this->agent, 'web')->get($url)->assertOk()->assertSee('Edit Profil')->assertSee('Keamanan Akun')->assertSee('@prospektus.qa')
            ->assertDontSee('Draft portfolio')->assertDontSee('Archived portfolio')->assertDontSee($this->agent->email);
    }

    public function test_listing_audit_is_metadata_only_and_repeated_publication_is_not_logged_twice(): void
    {
        Schema::create('audit_logs',function(\Illuminate\Database\Schema\Blueprint $table):void {
            $table->id();$table->integer('user_id')->nullable();$table->string('action');
            $table->text('old_values')->nullable();$table->text('new_values')->nullable();$table->timestamp('created_at')->nullable();
        });
        config(['security.qa_rollout'=>true]);$id=$this->draft();$this->image($id);
        $this->postJson('/api/agent/listings/'.$id.'/publish')->assertOk();
        $this->postJson('/api/agent/listings/'.$id.'/publish')->assertOk();
        $this->postJson('/api/agent/listings/'.$id.'/archive')->assertOk();
        $this->postJson('/api/agent/listings/'.$id.'/archive')->assertOk();
        $this->assertSame(1,\App\Models\AuditLog::where('action','listing.published')->count());
        $this->assertSame(1,\App\Models\AuditLog::where('action','listing.archived')->count());
        foreach(\App\Models\AuditLog::all()as$record){
            $this->assertTrue(app(\App\Services\SecurityAudit::class)->authentic($record));
            $this->assertSame($id,$record->new_values['listing_id']);
            $this->assertStringNotContainsString($this->payload['title'],$record->toJson());
        }
    }
    public function test_existing_download_switch_and_signed_link_work_for_new_private_media(): void
    {
        $id=$this->draft();$media=$this->image($id);
        $this->postJson('/api/agent/listings/'.$id.'/publish')->assertOk();$post=Post::firstOrFail();
        $this->agent->role->permissions()->attach(Permission::create(['name'=>'interact_posts'])->id);
        $setting=\App\Models\AppSetting::create(['setting_key'=>'allow_photo_download','setting_value'=>'false','data_type'=>'boolean']);
        $this->postJson('/api/posts/'.$post->id.'/downloads',['media_ids'=>[$media->id]])->assertForbidden();
        $setting->update(['setting_value'=>'true']);
        $url=$this->postJson('/api/posts/'.$post->id.'/downloads',['media_ids'=>[$media->id]])->assertCreated()->json('files.0.url');
        $file=$this->get($url)->assertOk()->assertHeader('Content-Type','image/png');
        $this->assertStringStartsWith('attachment;',$file->headers->get('Content-Disposition'));
        $setting->update(['setting_value'=>'false']);$this->get($url)->assertForbidden();
    }
    public function test_customer_detail_only_exposes_typed_public_specs_even_for_legacy_data(): void
    {
        $id=$this->draft();$this->image($id);$this->postJson('/api/agent/listings/'.$id.'/publish')->assertOk();
        Listing::findOrFail($id)->update(['orientation'=>'Timur','additional_specs'=>[
            'carport'=>0,'garage'=>'2','apartment_floor'=>10,'tower'=>['private'=>'PRIVATE LEGACY VALUE'],
            'internal_note'=>'PRIVATE LEGACY VALUE']]);
        $response=$this->getJson('/api/posts/'.Post::firstOrFail()->id)->assertOk()
            ->assertJsonPath('data.listing.orientation','Timur')->assertJsonPath('data.listing.additional_specs.carport',0)
            ->assertJsonPath('data.listing.additional_specs.garage',2)->assertJsonPath('data.listing.additional_specs.apartment_floor',10)
            ->assertJsonMissingPath('data.listing.additional_specs.tower')->assertJsonMissingPath('data.listing.additional_specs.internal_note');
        $this->assertStringNotContainsString('PRIVATE LEGACY VALUE',$response->getContent());
        Listing::findOrFail($id)->update(['additional_specs'=>'PRIVATE SCALAR']);
        $this->getJson('/api/posts/'.Post::firstOrFail()->id)->assertOk()->assertJsonPath('data.listing.additional_specs',[]);
    }
}
