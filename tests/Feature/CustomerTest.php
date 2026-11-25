<?php

namespace Tests\Feature;

use App\Models\{Comment, Conversation, Notification, Permission, Role, SurveyRequest, User};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema, Storage};
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FeedDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use FeedDatabase;
    private User $customer;
    private User $other;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp(); $this->createFeedDatabase();
        if (!Schema::hasTable('notifications')) Schema::create('notifications',function(Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('type'); $t->string('title'); $t->text('message')->nullable();
            $t->string('related_type')->nullable(); $t->unsignedBigInteger('related_id')->nullable();
            $t->text('data')->nullable(); $t->timestamp('read_at')->nullable(); $t->timestamp('created_at')->nullable();
        });
        $role=Role::create(['name'=>'customer','level'=>1]);
        foreach(['message_agents','request_surveys','interact_posts'] as $p) $role->permissions()->attach(Permission::create(['name'=>$p]));
        $this->customer=User::create(['name'=>'Customer','role_id'=>$role->id,'status'=>'active','password'=>'password123']);
        $this->other=User::create(['name'=>'Other','role_id'=>$role->id,'status'=>'active']);
        $agent=Role::create(['name'=>'agent','level'=>2]);
        $this->agent=User::create(['name'=>'Agent','role_id'=>$agent->id,'status'=>'active','phone'=>'PRIVATE PHONE','email'=>'private@example.test']);
    }

    private function thread(User $owner): Conversation
    {
        $c=Conversation::create(['type'=>'direct','created_by'=>$owner->id,'status'=>'active','last_message_at'=>now()]);
        $m=$c->members()->create(['user_id'=>$owner->id,'member_role'=>'customer']);
        $c->members()->create(['user_id'=>$this->agent->id,'member_role'=>'agent']);
        $c->messages()->create(['sender_member_id'=>$m->id,'message_type'=>'text','body'=>'Own message']);
        return $c;
    }

    public function test_guest_and_other_roles_cannot_use_customer_workspace(): void
    {
        foreach(['profile','surveys','conversations','notifications','activity?type=likes','following'] as $path) $this->getJson('/api/customer/'.$path)->assertUnauthorized();
        Sanctum::actingAs($this->agent); $this->getJson('/api/customer/profile')->assertForbidden();
    }

    public function test_profile_updates_allowlist_and_rejects_administrative_and_contact_changes(): void
    {
        Sanctum::actingAs($this->customer);
        foreach(['role_id','branch_id','permissions','status','email','phone','password','whatsapp','phone_verified_at'] as $key) {
            \Illuminate\Support\Facades\Cache::flush(); // Isolated array cache; each validation case has its own rate-limit window.
            $this->postJson('/api/customer/profile',['name'=>'New',$key=>$key==='permissions'?['all']:999])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->postJson('/api/customer/profile',['name'=>'Updated','username'=>'customer.one','bio'=>'My profile'])->assertOk()->assertJsonPath('data.name','Updated');
        $this->assertSame('customer',$this->customer->fresh()->role->name);
        $this->getJson('/api/customer/profile')->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.password')->assertJsonPath('availability.whatsapp',false);
    }

    public function test_avatar_upload_rejects_non_images_and_stores_valid_image(): void
    {
        Storage::fake('public'); Sanctum::actingAs($this->customer);
        $this->post('/api/customer/profile',['name'=>'Customer','photo'=>UploadedFile::fake()->create('bad.svg',1,'image/svg+xml')],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/customer/profile',['name'=>'Customer','photo'=>UploadedFile::fake()->createWithContent('forged.jpg','not image content')],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/customer/profile',['name'=>'Customer','photo'=>UploadedFile::fake()->image('oversized.jpg')->size(2049)],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/customer/profile',['name'=>'Customer','photo'=>UploadedFile::fake()->image('wide.jpg',4097,2)],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/customer/profile',['name'=>'Customer','photo'=>UploadedFile::fake()->image('avatar.jpg')],['Accept'=>'application/json'])->assertOk();
        Storage::disk('public')->assertExists($this->customer->fresh()->photo);
    }

    public function test_browsing_does_not_consume_the_profile_write_limit(): void
    {
        Sanctum::actingAs($this->customer);
        for($i=0;$i<12;$i++)$this->getJson('/api/customer/profile')->assertOk();
        for($i=0;$i<10;$i++)$this->postJson('/api/customer/profile',['name'=>'Customer'])->assertOk();
        $this->postJson('/api/customer/profile',['name'=>'Customer'])->assertStatus(429);
    }

    public function test_conversation_membership_is_required_and_sender_cannot_be_spoofed(): void
    {
        $own=$this->thread($this->customer); $foreign=$this->thread($this->other);
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/customer/conversations')->assertOk()->assertJsonCount(1,'data')->assertJsonMissingPath('data.0.agent.email')->assertJsonMissingPath('data.0.agent.phone');
        $this->getJson('/api/customer/conversations/'.$foreign->id.'/messages')->assertNotFound();
        $this->postJson('/api/customer/conversations/'.$foreign->id.'/messages',['body'=>'Attack'])->assertNotFound();
        $this->postJson('/api/customer/conversations/'.$own->id.'/messages',['body'=>'Hello'])->assertForbidden()->assertJsonPath('code','PHONE_VERIFICATION_REQUIRED');
        $this->customer->update(['phone_verified_at'=>now()]);
        $this->postJson('/api/customer/conversations/'.$own->id.'/messages',['body'=>'Hello','sender_member_id'=>999])->assertUnprocessable();
        $this->postJson('/api/customer/conversations/'.$own->id.'/messages',['body'=>'Hello'])->assertCreated();
        $this->getJson('/api/customer/conversations/'.$own->id.'/messages')->assertOk()->assertJsonCount(2,'data')->assertJsonPath('data.0.mine',true);
    }

    public function test_notifications_are_owned_paginated_and_raw_data_is_never_exposed(): void
    {
        $foreign=Notification::create(['user_id'=>$this->other->id,'type'=>'survey','title'=>'Private','data'=>['secret'=>'NEVER EXPOSE']]);
        for($i=0;$i<21;$i++)Notification::create(['user_id'=>$this->customer->id,'type'=>'system','title'=>'Own '.$i,'data'=>['secret'=>'NEVER EXPOSE']]);
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/customer/notifications')->assertOk()->assertJsonCount(20,'data')->assertJsonPath('has_more',true)->assertJsonMissingPath('data.0.data');
        $this->getJson('/api/customer/notifications?page=2')->assertJsonCount(1,'data')->assertJsonPath('has_more',false);
        $this->patchJson('/api/customer/notifications/'.$foreign->id.'/read')->assertNotFound();
        $id=Notification::where('user_id',$this->customer->id)->first()->id;
        $this->patchJson('/api/customer/notifications/'.$id.'/read')->assertOk();
        $this->assertNotNull(Notification::find($id)->read_at);
    }

    public function test_customer_cannot_edit_another_users_comment(): void
    {
        $c=Comment::create(['user_id'=>$this->other->id,'post_id'=>1,'content'=>'Private ownership','moderation_status'=>'active']);
        Sanctum::actingAs($this->customer);
        $this->patchJson('/api/customer/comments/'.$c->id,['content'=>'Changed'])->assertForbidden();
        $this->deleteJson('/api/customer/comments/'.$c->id)->assertForbidden();
        $this->assertSame('Private ownership',$c->fresh()->content);
    }

    public function test_inverted_price_range_is_rejected(): void
    {
        $this->getJson('/api/feed?min_price=200&max_price=100')->assertUnprocessable()->assertJsonValidationErrors('max_price');
    }
}
