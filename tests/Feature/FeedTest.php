<?php

namespace Tests\Feature;

use App\Models\{AgentProfile, AppSetting, Branch, Comment, Follow, Listing, Location, Permission, Post, PostMedia, PropertyCategory, Role, SecondaryProperty, User};
use Illuminate\Support\Facades\{DB, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\Support\FeedDatabase;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use FeedDatabase;
    private User $customer;
    private User $agent;
    private Listing $listing;
    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFeedDatabase();
        $customer = Role::create(['name'=>'customer','level'=>1]);
        $agent = Role::create(['name'=>'agent','level'=>2]);
        foreach (['interact_posts','message_agents','request_surveys'] as $name) {
            $permission = Permission::create(['name'=>$name,'description'=>$name]);
            $customer->permissions()->attach($permission); $agent->permissions()->attach($permission);
        }
        $this->customer = User::create(['name'=>'Customer','email'=>'customer@example.test','password'=>'password123','role_id'=>$customer->id,'status'=>'active']);
        $this->agent = User::create(['name'=>'Agent','role_id'=>$agent->id,'status'=>'active']);
        $location = Location::create(['label'=>'Area publik','city'=>'Batam','latitude'=>'1.1234567','longitude'=>'104.1234567','public_latitude'=>'1.1000000','public_longitude'=>'104.1000000','public_radius_m'=>500,'visibility'=>'approximate','address'=>'PRIVATE ADDRESS','google_maps_url'=>'https://example.test/private']);
        $category = PropertyCategory::create(['name'=>'Rumah','slug'=>'rumah']);
        $secondary = SecondaryProperty::create(['property_code'=>'TEST-1','title'=>'Property','category_id'=>$category->id,'location_id'=>$location->id]);
        $this->listing = Listing::create(['listing_code'=>'LIST-1','listing_type'=>'secondary','transaction_type'=>'sale','agent_id'=>$this->agent->id,'branch_id'=>1,'category_id'=>$category->id,'secondary_property_id'=>$secondary->id,'title'=>'Rumah taman','price'=>1200000000,'status'=>'active']);
        $this->post = Post::create(['user_id'=>$this->agent->id,'listing_id'=>$this->listing->id,'content_type'=>'video','status'=>'published','moderation_status'=>'active','published_at'=>now()->subHour()]);
        $this->post->media()->create(['media_type'=>'video','file_url'=>'posts/tour.mp4','width'=>1920,'height'=>1080,'sort_order'=>1]);
    }

    public function test_customer_can_edit_and_soft_delete_own_visible_comment(): void
    {
        Sanctum::actingAs($this->customer);
        $comment=Comment::create(['post_id'=>$this->post->id,'user_id'=>$this->customer->id,'content'=>'Original','moderation_status'=>'active']);
        $this->patchJson('/api/customer/comments/'.$comment->id,['content'=>'Updated'])->assertOk();
        $this->assertSame('Updated',$comment->fresh()->content);
        $this->deleteJson('/api/customer/comments/'.$comment->id)->assertOk();
        $this->assertNotNull(Comment::withTrashed()->find($comment->id)->deleted_at);
        $this->getJson('/api/posts/'.$this->post->id.'/comments')->assertJsonCount(0,'data');
    }

    public function test_customer_survey_history_is_owned_and_does_not_expose_private_location(): void
    {
        Sanctum::actingAs($this->customer);
        $this->customer->update(['phone_verified_at'=>now()]);
        $this->postJson('/api/listings/'.$this->listing->id.'/surveys',['requested_at'=>now()->addDay()->toIso8601String(),'note'=>'Own visit'])->assertCreated();
        $this->getJson('/api/customer/surveys')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.status','pending')->assertJsonPath('data.0.can_cancel',false)->assertJsonMissingPath('data.0.listing.location');
        $other=User::create(['name'=>'Other customer','role_id'=>$this->customer->role_id,'status'=>'active']);
        Sanctum::actingAs($other);
        $this->getJson('/api/customer/surveys')->assertOk()->assertJsonCount(0,'data');
    }

    public function test_customer_activity_excludes_hidden_posts_and_following_excludes_inactive_agents(): void
    {
        Sanctum::actingAs($this->customer);
        $this->postJson('/api/posts/'.$this->post->id.'/like')->assertOk();
        $this->postJson('/api/agents/'.$this->agent->id.'/follow')->assertOk();
        $this->getJson('/api/customer/activity?type=likes')->assertJsonCount(1,'data');
        $this->getJson('/api/customer/following')->assertJsonCount(1,'data')->assertJsonMissingPath('data.0.email');
        $this->post->update(['moderation_status'=>'removed']);
        $this->getJson('/api/customer/activity?type=likes')->assertJsonCount(0,'data');
        $this->agent->update(['status'=>'suspended']);
        $this->getJson('/api/customer/following')->assertJsonCount(0,'data');
    }

    public function test_following_feed_uses_listing_agent_even_when_post_author_differs(): void
    {
        $publisher=User::create(['name'=>'Publisher','role_id'=>$this->agent->role_id,'status'=>'active']);
        $this->post->update(['user_id'=>$publisher->id]);
        Follow::create(['follower_id'=>$this->customer->id,'following_id'=>$this->agent->id]);
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/feed/following')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.agent.id',$this->agent->id);
    }

    public function test_reactions_do_not_consume_survey_or_download_limits(): void
    {
        Sanctum::actingAs($this->customer);$this->customer->update(['phone_verified_at'=>now()]);
        for($i=0;$i<12;$i++)$this->postJson('/api/posts/'.$this->post->id.'/like')->assertOk();
        $this->postJson('/api/listings/'.$this->listing->id.'/surveys',['requested_at'=>now()->addDay()->toIso8601String()])->assertCreated();
    }

    public function test_identical_pending_survey_retries_do_not_create_duplicate_requests(): void
    {
        Sanctum::actingAs($this->customer);$this->customer->update(['phone_verified_at'=>now()]);
        $data=['requested_at'=>now()->addDay()->startOfMinute()->toIso8601String(),'note'=>'Same requested visit'];
        $first=$this->postJson('/api/listings/'.$this->listing->id.'/surveys',$data)->assertCreated()->json('data.id');
        $this->postJson('/api/listings/'.$this->listing->id.'/surveys',$data)->assertOk()->assertJsonPath('data.id',$first);
        $this->assertDatabaseCount('survey_requests',1);
    }

    public function test_guest_can_open_home_and_public_feed(): void
    {
        $this->get('/')->assertOk()->assertSee('promerty-feed', false)->assertSee('Masuk Ke Promerty');
        $this->getJson('/api/feed')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.listing.title','Rumah taman');
        $this->getJson('/api/agents/'.$this->agent->id)->assertOk()->assertJsonMissingPath('data.email');
    }

    public function test_compact_sidebar_and_comment_panel_are_available(): void
    {
        $this->get('/')->assertOk()->assertSee('Sidebar Promerty')->assertSee('sidebar-search', false)
            ->assertSee('feed-comments', false)->assertSee('data-comment-resize', false)
            ->assertSee('Navigasi mobile')->assertSee('comments-count', false);
    }

    public function test_saved_feed_is_private_and_scoped_to_viewer(): void
    {
        $this->getJson('/api/feed?saved=1')->assertUnauthorized();
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/feed?saved=1')->assertJsonCount(0, 'data');
        $this->postJson('/api/posts/'.$this->post->id.'/save')->assertOk();
        $this->getJson('/api/feed?saved=1')->assertJsonCount(1, 'data');
        Sanctum::actingAs($this->agent);
        $this->getJson('/api/feed?saved=1')->assertJsonCount(0, 'data');
    }

    public function test_search_finds_agent_and_guest_reads_public_comment_fields(): void
    {
        $this->agent->update(['username'=>'agent_unique']);
        $this->getJson('/api/feed?q=agent_unique')->assertOk()->assertJsonCount(1, 'data');
        Comment::create(['post_id'=>$this->post->id,'user_id'=>$this->agent->id,'content'=>'Public comment','moderation_status'=>'active']);
        $this->getJson('/api/posts/'.$this->post->id.'/comments')->assertOk()
            ->assertJsonPath('data.0.user.username', 'agent_unique')->assertJsonPath('data.0.content', 'Public comment')
            ->assertJsonMissingPath('data.0.user.email')->assertJsonMissingPath('data.0.user.phone');
    }

    public function test_guest_cannot_like_comment_save_follow_or_read_following(): void
    {
        foreach (['like','save','comments'] as $action) $this->postJson('/api/posts/'.$this->post->id.'/'.$action, ['content'=>'Hello'])->assertUnauthorized();
        $this->postJson('/api/agents/'.$this->agent->id.'/follow')->assertUnauthorized();
        $this->getJson('/api/feed/following')->assertUnauthorized();
    }

    public function test_customer_can_like_and_save_idempotently_and_remove(): void
    {
        Sanctum::actingAs($this->customer);
        foreach (['like'=>'likes','save'=>'saves'] as $action=>$table) {
            $url='/api/posts/'.$this->post->id.'/'.$action;
            $this->postJson($url)->assertOk()->assertJsonPath('active',true);
            $this->postJson($url)->assertOk(); $this->assertDatabaseCount($table,1);
            $this->getJson('/api/feed')->assertJsonPath('data.0.viewer.'.($action==='like'?'liked':'saved'),true);
            $this->deleteJson($url)->assertOk()->assertJsonPath('active',false); $this->assertDatabaseCount($table,0);
        }
    }

    public function test_self_follow_is_forbidden_and_following_feed_works(): void
    {
        Sanctum::actingAs($this->agent);
        $this->postJson('/api/agents/'.$this->agent->id.'/follow')->assertStatus(422);
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/feed/following')->assertJsonCount(0,'data');
        $this->postJson('/api/agents/'.$this->agent->id.'/follow')->assertOk();
        $this->getJson('/api/feed/following')->assertJsonCount(1,'data')->assertJsonPath('data.0.viewer.following',true);
    }

    public function test_visibility_filters_and_empty_feed(): void
    {
        foreach ([['status'=>'draft'],['moderation_status'=>'removed'],['moderation_status'=>'under_review'],['published_at'=>null],['published_at'=>now()->addDay()],['deleted_at'=>now()]] as $attributes) {
            $copy=$this->post->replicate(); $copy->forceFill($attributes)->save();
        }
        $this->getJson('/api/feed')->assertOk()->assertJsonCount(1,'data');
        $this->post->update(['status'=>'archived']);
        $this->getJson('/api/feed')->assertOk()->assertJsonCount(0,'data')->assertJsonPath('next_cursor',null);
        $this->getJson('/api/posts/'.$this->post->id)->assertNotFound();
    }

    public function test_secondary_location_never_exposes_private_coordinates_or_urls(): void
    {
        foreach ([null,$this->customer] as $user) {
            if ($user) Sanctum::actingAs($user);
            $response=$this->getJson('/api/posts/'.$this->post->id)->assertOk()
                ->assertJsonPath('data.listing.location.latitude','1.1000000')->assertJsonPath('data.listing.location.radius_m',500)
                ->assertJsonMissingPath('data.listing.location.address')->assertJsonMissingPath('data.listing.location.google_maps_url');
            $this->assertStringNotContainsString('1.1234567',$response->getContent());
            $this->assertStringNotContainsString('104.1234567',$response->getContent());
        }
        $this->listing->secondaryProperty->location->update(['public_latitude'=>null,'public_longitude'=>null]);
        $this->getJson('/api/posts/'.$this->post->id)->assertJsonPath('data.listing.location.latitude',null);
    }

    public function test_media_orientation_and_carousel_metadata(): void
    {
        $url='/api/posts/'.$this->post->id;
        $this->getJson($url)->assertJsonPath('data.media.0.orientation','landscape')->assertJsonPath('data.media.0.aspect_ratio',1.7778);
        $this->post->media()->first()->update(['width'=>1080,'height'=>1920]);
        $this->getJson($url)->assertJsonPath('data.media.0.orientation','portrait');
        $this->post->update(['content_type'=>'photo']);
        $this->post->media()->first()->update(['media_type'=>'image']);
        for($i=2;$i<=30;$i++) $this->post->media()->create(['media_type'=>'image','file_url'=>'posts/'.$i.'.jpg','sort_order'=>$i]);
        $this->getJson($url)->assertJsonCount(30,'data.media');
    }

    public function test_unverified_customer_cannot_message_or_survey(): void
    {
        Sanctum::actingAs($this->customer);
        foreach (['messages','surveys'] as $action) $this->postJson('/api/listings/'.$this->listing->id.'/'.$action)->assertForbidden()->assertJsonPath('code','PHONE_VERIFICATION_REQUIRED');
        $this->assertDatabaseCount('survey_requests',0); $this->assertDatabaseCount('messages',0);
    }

    public function test_verified_customer_can_message_and_request_survey_and_agent_cannot_survey_own_listing(): void
    {
        $this->customer->update(['phone_verified_at'=>now()]); Sanctum::actingAs($this->customer);
        $this->postJson('/api/listings/'.$this->listing->id.'/messages',['body'=>'Masih tersedia?'])->assertCreated();
        $this->postJson('/api/listings/'.$this->listing->id.'/messages',['body'=>'Boleh melihat?'])->assertCreated();
        $this->assertDatabaseCount('conversations',1); $this->assertDatabaseCount('messages',2);
        $this->getJson('/api/listings/'.$this->listing->id.'/messages')->assertOk()->assertJsonCount(2,'data');
        $this->postJson('/api/listings/'.$this->listing->id.'/surveys',['requested_at'=>now()->addDay()->toIso8601String()])->assertCreated();
        Sanctum::actingAs($this->agent);
        $this->postJson('/api/listings/'.$this->listing->id.'/surveys',['requested_at'=>now()->addDay()->toIso8601String()])->assertStatus(422);
    }

    public function test_comment_moderation_and_reply_parent_are_enforced(): void
    {
        Sanctum::actingAs($this->customer);
        $url='/api/posts/'.$this->post->id.'/comments';
        $id=$this->postJson($url,['content'=>'Parent'])->assertCreated()->json('data.id');
        $this->postJson($url,['content'=>'Reply','parent_id'=>$id])->assertCreated();
        Comment::find($id)->update(['moderation_status'=>'removed']);
        $this->getJson($url)->assertJsonCount(0,'data');
        $this->getJson($url.'?parent_id='.$id)->assertJsonCount(0,'data');
        $this->postJson($url,['content'=>'Not allowed','parent_id'=>$id])->assertNotFound();
        $this->getJson('/api/feed')->assertJsonPath('data.0.counts.comments',0);
    }

    public function test_download_flags_sessions_signed_links_and_media_ownership(): void
    {
        $url='/api/posts/'.$this->post->id.'/downloads'; $media=$this->post->media()->first();
        $this->postJson($url,['media_ids'=>[$media->id]])->assertForbidden();
        AppSetting::create(['setting_key'=>'allow_video_download','setting_value'=>'true','data_type'=>'boolean']);
        $this->postJson($url,['media_ids'=>[999]])->assertStatus(422);
        Storage::fake('public'); Storage::disk('public')->put('posts/tour.mp4','test bytes');
        $download=$this->postJson($url,['media_ids'=>[$media->id]])->assertCreated();
        $this->assertDatabaseCount('download_sessions',1);$this->assertDatabaseCount('download_items',1);
        $link=$download->json('files.0.url'); $this->get($link)->assertOk();
        $this->get(strtok($link,'?'))->assertForbidden();
        AppSetting::where('setting_key','allow_video_download')->update(['setting_value'=>'false']);
        $this->get($link)->assertForbidden();
    }

    public function test_guest_share_is_logged_and_suspended_users_are_blocked(): void
    {
        $this->postJson('/api/posts/'.$this->post->id.'/shares',['platform'=>'copy'])->assertCreated();$this->assertDatabaseCount('shares',1);
        $this->customer->update(['status'=>'suspended']);Sanctum::actingAs($this->customer);
        $this->postJson('/api/posts/'.$this->post->id.'/like')->assertForbidden();
    }

    public function test_video_download_retains_private_original_and_rechecks_listing_consent_and_visibility(): void
    {
        Storage::fake('local');
        $key = str_repeat('d', 48).'.mp4';
        $original = 'original video fixture bytes';
        Storage::disk('local')->put('agent-listings/'.$key, $original);
        Storage::disk('local')->put('agent-listings/'.$key.'.playback', 'optimized fixture bytes');
        AppSetting::create(['setting_key'=>'allow_video_download','setting_value'=>'true','data_type'=>'boolean']);
        $media = $this->post->media()->firstOrFail();
        $media->update(['file_url'=>'agent-private/'.$key]);
        $this->listing->update(['title'=>'Tamarama Residence — Gaharu Loft Mezzanine','additional_specs'=>['allow_video_download'=>true]]);
        $request = '/api/posts/'.$this->post->id.'/downloads';
        $payload = ['media_ids'=>[$media->id]];
        $link = $this->postJson($request, $payload)->assertCreated()->json('files.0.url');
        $download = $this->get($link)->assertOk()->assertDownload('Promerty-Tamarama-Residence-Gaharu-Loft-Mezzanine.mp4')->assertHeader('Content-Type','video/mp4');
        $this->assertSame(hash('sha256', $original), hash_file('sha256', $download->baseResponse->getFile()->getPathname()));
        $playback = $this->get('/api/listing-media/'.$key)->assertOk();
        $this->assertStringEndsWith('.playback', $playback->baseResponse->getFile()->getPathname());
        $this->listing->update(['additional_specs'=>['allow_video_download'=>false]]);
        $this->getJson('/api/feed')->assertJsonPath('data.0.media.0.downloadable', false);
        $this->postJson($request, $payload)->assertForbidden();
        $this->get($link)->assertForbidden();
        $this->listing->update(['additional_specs'=>['allow_video_download'=>true]]);
        foreach (['draft', 'archived'] as $status) {
            $this->listing->update(['status'=>$status]);
            $this->postJson($request, $payload)->assertNotFound();
            $this->get($link)->assertNotFound();
        }
        $this->listing->update(['status'=>'active']);
        $media->update(['file_url'=>'https://example.test/video.mp4']);
        $this->postJson($request, $payload)->assertUnprocessable();
        $this->get($link)->assertNotFound();
    }

    public function test_interaction_writes_require_permission_and_are_owned_by_the_viewer(): void
    {
        $path = '/api/posts/'.$this->post->id;
        $this->getJson($path.'/comments')->assertOk();
        $this->postJson($path.'/comments', ['content'=>'Guest'])->assertUnauthorized();
        foreach (['like', 'save'] as $action) {
            $this->postJson($path.'/'.$action)->assertUnauthorized();
            $this->deleteJson($path.'/'.$action)->assertUnauthorized();
        }
        Sanctum::actingAs($this->customer);
        foreach (['like', 'save'] as $action) {
            $this->postJson($path.'/'.$action)->assertOk()->assertJsonPath('count', 1);
            $this->postJson($path.'/'.$action)->assertOk()->assertJsonPath('count', 1);
            $this->deleteJson($path.'/'.$action)->assertOk()->assertJsonPath('count', 0);
        }
        $this->postJson($path.'/comments', ['content'=>''])->assertUnprocessable();
        $comment = $this->postJson($path.'/comments', ['content'=>'<b>plain text</b>'])->assertCreated()->json('data.id');
        Sanctum::actingAs($this->agent);
        $this->patchJson('/api/personal/comments/'.$comment, ['content'=>'Other'])->assertForbidden();
        $this->deleteJson('/api/personal/comments/'.$comment)->assertForbidden();
        $this->getJson($path.'/comments')->assertJsonPath('data.0.content', '<b>plain text</b>');
        $this->postJson($path.'/like')->assertOk();
        $this->postJson($path.'/save')->assertOk();
        $this->postJson($path.'/comments', ['content'=>'Agent response'])->assertCreated();
        foreach (['branch_manager'=>3, 'admin'=>4, 'owner'=>6] as $name => $level) {
            $role = Role::create(['name'=>$name,'level'=>$level]);
            Sanctum::actingAs(User::create(['name'=>'QA '.$name,'role_id'=>$role->id,'status'=>'active']));
            $this->getJson($path)->assertOk();
            $this->postJson($path.'/like')->assertForbidden();
            $this->postJson($path.'/save')->assertForbidden();
            $this->postJson($path.'/comments', ['content'=>'No permission'])->assertForbidden();
        }
    }

    public function test_all_five_roles_can_manage_only_their_personal_interactions(): void
    {
        $post = '/api/posts/'.$this->post->id;
        $activity = '/api/social/activity';
        $this->getJson($activity.'?type=comments')->assertUnauthorized();
        $this->getJson('/api/feed?saved=1')->assertUnauthorized();
        $permission = Permission::where('name','interact_posts')->sole();
        $actors = [$this->customer, $this->agent];
        foreach (['branch_manager'=>3,'admin'=>4,'owner'=>6] as $name => $level) {
            $role = Role::create(['name'=>$name,'level'=>$level]);
            $this->assertContains($permission->name, \App\Support\RoleCatalog::permissions($name));
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $actors[] = User::create(['name'=>'QA '.$name,'role_id'=>$role->id,'status'=>'active']);
        }

        $foreignComment = null;
        foreach ($actors as $index => $actor) {
            Sanctum::actingAs($actor);
            $this->getJson('/api/feed?saved=1')->assertJsonCount(0,'data');
            $this->getJson($activity.'?type=comments&user_id='.$this->customer->id)->assertJsonCount(0,'data');
            $this->postJson("$post/like")->assertOk();
            $this->postJson("$post/save")->assertOk();
            $this->getJson('/api/feed?saved=1')->assertJsonPath('data.0.id',$this->post->id);
            $id = $this->postJson("$post/comments",['content'=>'Personal '.$index,'user_id'=>999])
                ->assertCreated()->assertJsonPath('data.user.id',$actor->id)->json('data.id');
            $url = "/api/social/comments/$id";
            $this->patchJson($url,['content'=>'Updated '.$index])->assertOk();
            $this->getJson($activity.'?type=comments')->assertJsonCount(1,'data')->assertJsonPath('data.0.content','Updated '.$index);
            if ($foreignComment !== null) {
                $this->patchJson("/api/social/comments/$foreignComment",['content'=>'Other'])->assertForbidden();
                $this->deleteJson("/api/social/comments/$foreignComment")->assertForbidden();
                $this->deleteJson($url)->assertOk();
                $this->deleteJson("$post/save")->assertOk();
                $this->deleteJson("$post/like")->assertOk();
            } else {
                $foreignComment = $id;
            }
            if ($index >= 2) {
                $this->postJson('/api/agents/'.$this->agent->id.'/follow')->assertForbidden();
                $this->getJson('/api/customer/profile')->assertForbidden();
                $this->getJson('/api/agent/listings')->assertForbidden();
            }
        }
        $this->assertDatabaseHas('comments',['id'=>$foreignComment,'user_id'=>$this->customer->id,'content'=>'Updated 0','deleted_at'=>null]);
        $this->assertDatabaseCount('likes',1);
        $this->assertDatabaseCount('saves',1);
        $role = $actors[4]->role;
        $role->permissions()->detach($permission->id);
        $this->getJson('/api/feed?saved=1')->assertForbidden();
        $this->getJson($activity.'?type=comments')->assertForbidden();
    }

    public function test_cursor_pagination_search_and_query_count_are_bounded(): void
    {
        for($i=0;$i<10;$i++){
            $post=$this->post->replicate();$post->published_at=now()->subMinutes($i);$post->save();
            $post->media()->create(['media_type'=>'video','file_url'=>'posts/tour.mp4','sort_order'=>1]);
        }
        DB::enableQueryLog();$first=$this->getJson('/api/feed?q=Rumah&type=secondary')->assertOk()->assertJsonCount(8,'data');
        $this->assertLessThan(30,count(DB::getQueryLog()));DB::disableQueryLog();
        $second=$this->getJson('/api/feed?cursor='.urlencode($first->json('next_cursor')))->assertOk()->assertJsonCount(3,'data');
        $this->assertEmpty(array_intersect(array_column($first->json('data'),'id'),array_column($second->json('data'),'id')));
        $this->getJson('/api/feed?type=primary')->assertJsonCount(0,'data');
    }

    public function test_recommendations_allow_guests_and_all_roles_without_admin_grants(): void
    {
        $this->getJson('/api/feed')->assertOk()->assertJsonPath('data.0.id',$this->post->id)
            ->assertHeader('Cache-Control','no-store, private');
        $this->postJson('/api/posts/'.$this->post->id.'/like')->assertUnauthorized();
        foreach ([$this->customer,$this->agent, ...array_map(function (string $name, int $level) {
            $role=Role::create(['name'=>$name,'level'=>$level]);
            return User::create(['name'=>'Role fixture','role_id'=>$role->id,'status'=>'active']);
        }, ['branch_manager','admin','owner'], [3,4,6])] as $viewer) {
            Sanctum::actingAs($viewer);
            $this->getJson('/api/feed')->assertOk()->assertJsonPath('data.0.id',$this->post->id);
        }
    }

    public function test_recommendations_hide_missing_media_and_staging_until_a_public_file_exists(): void
    {
        Storage::fake('local');
        $key=str_repeat('b',48).'.mp4';
        $media=$this->post->media()->first();
        $media->update(['file_url'=>'agent-private/'.$key]);
        Storage::disk('local')->put('agent-video-staging/'.str_repeat('b',48).'.part','synthetic placeholder');
        $this->getJson('/api/feed')->assertOk()->assertJsonCount(0,'data');
        $this->get('/api/listing-media/'.$key)->assertNotFound();
        Storage::disk('local')->put('agent-listings/'.$key,'synthetic byte fixture');
        $this->getJson('/api/feed')->assertOk()->assertJsonPath('data.0.media.0.url','/api/listing-media/'.$key);
        $this->get('/api/listing-media/'.$key)->assertOk();
        $this->listing->update(['status'=>'archived']);
        $this->getJson('/api/feed')->assertOk()->assertJsonCount(0,'data');
        $this->get('/api/listing-media/'.$key)->assertNotFound();
    }

    public function test_web_session_registration_always_creates_customer(): void
    {
        $response=$this->postJson('/web-auth/register',['name'=>'New user','email'=>'new@example.test','password'=>'password123','password_confirmation'=>'password123','role_id'=>999]);
        $response->assertOk()->assertJsonPath('user.role','customer');
        $this->getJson('/web-auth/me')->assertJsonPath('user.name','New user');
        $this->postJson('/web-auth/logout')->assertOk()->assertJsonPath('user',null);
    }

    public function test_primary_exact_location_and_missing_media_dimensions_are_safe(): void
    {
        $location=Location::create(['label'=>'Project public','latitude'=>'1.2222222','longitude'=>'104.2222222','visibility'=>'exact']);
        $project=\App\Models\Project::create(['name'=>'Project','location_id'=>$location->id]);
        $this->listing->update(['listing_type'=>'primary','project_id'=>$project->id,'secondary_property_id'=>null]);
        $this->post->media()->first()->update(['width'=>null,'height'=>null]);
        $this->getJson('/api/posts/'.$this->post->id)->assertOk()->assertJsonPath('data.listing.location.visibility','exact')
            ->assertJsonPath('data.listing.location.latitude','1.2222222')->assertJsonPath('data.media.0.orientation','unknown');
    }

    public function test_permissions_are_required_and_view_tracking_is_deduplicated(): void
    {
        $this->postJson('/api/posts/'.$this->post->id.'/views',['watch_duration_seconds'=>3])->assertNoContent();
        $this->postJson('/api/posts/'.$this->post->id.'/views',['watch_duration_seconds'=>3])->assertNoContent();
        $this->assertDatabaseCount('post_views',1);
        $this->customer->role->permissions()->detach();Sanctum::actingAs($this->customer);
        $this->postJson('/api/posts/'.$this->post->id.'/like')->assertForbidden();
    }
}
