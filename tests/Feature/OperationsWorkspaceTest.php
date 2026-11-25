<?php
namespace Tests\Feature;
use App\Models\{Agency,AgentProfile,Branch,Listing,PropertyCategory,Role,User};
use App\Services\AgentListingWorkflow;
use Database\Seeders\PromertySeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FeedDatabase;
use Tests\TestCase;

class OperationsWorkspaceTest extends TestCase
{
    use FeedDatabase;
    private Branch $branch;
    protected function setUp():void
    {
        parent::setUp();$this->createFeedDatabase();(new PromertySeeder)->run();
        $agency=Agency::create(['name'=>'Synthetic agency','slug'=>'ops-qa']);
        $this->branch=Branch::create(['agency_id'=>$agency->id,'name'=>'QA Batam','status'=>'active']);
    }
    private function account(string $role,?Branch $branch=null):User
    {
        $u=User::create(['name'=>'Synthetic '.$role,'role_id'=>Role::where('name',$role)->value('id'),
            'branch_id'=>($branch??$this->branch)->id,'status'=>'active']);
        if($role==='agent')AgentProfile::create(['user_id'=>$u->id,'verification_status'=>'verified']);
        return $u;
    }
    private function listing(User $agent,string $title):Listing
    {
        $cat=PropertyCategory::firstOrCreate(['slug'=>'rumah'],['name'=>'Rumah']);
        return app(AgentListingWorkflow::class)->save($agent,['title'=>$title,'category_id'=>$cat->id,
            'transaction_type'=>'sale','price'=>'1000000','price_period'=>'total','location_label'=>'Bengkong']);
    }

    public function test_shared_pages_keep_one_sidebar_for_the_viewing_role(): void
    {
        $agent = $this->account('agent');
        $portfolio = '/agents/'.$agent->id;
        foreach ([$portfolio, '/settings', '/help'] as $url) {
            $page = $this->get($url)->assertOk()->assertSee('aria-label="Navigasi Promerty"', false)
                ->assertDontSee('Dashboard Agent')->assertDontSee('Hak Akses');
            $this->assertSame(1, substr_count($page->getContent(), 'a-sidebar ws-sidebar'));
        }
        $this->get('/account/profile')->assertRedirect('/?panel=profile');

        foreach (['customer' => 'Ruang Customer', 'agent' => 'Ruang Agent', 'branch_manager' => 'Ruang Cabang',
            'admin' => 'Administrasi', 'owner' => 'Ruang Owner'] as $role => $label) {
            $viewer = $role === 'agent' ? $agent : $this->account($role);
            $this->actingAs($viewer, 'web');
            foreach ([$portfolio, '/account/profile', '/settings', '/help'] as $url) {
                $page = $this->get($url)->assertOk()->assertSee('aria-label="Navigasi '.$label.'"', false);
                $this->assertSame(1, substr_count($page->getContent(), 'a-sidebar ws-sidebar'));
                if ($role !== 'agent') $page->assertDontSee('Dashboard Agent');
                if ($role !== 'admin') $page->assertDontSee('Hak Akses');
            }
            if ($role !== 'agent') $this->get('/agent/listings')->assertForbidden();
        }
    }

    public function test_pending_agent_can_use_personal_navigation_without_listing_access(): void
    {
        $agent=$this->account('agent');
        $agent->agentProfile->update(['verification_status'=>'pending']);
        $this->getJson('/api/personal/profile')->assertUnauthorized();
        Sanctum::actingAs($agent);
        $this->getJson('/api/personal/profile')->assertOk()->assertJsonPath('data.role','agent');
        $this->getJson('/api/personal/conversations?scope=incoming')->assertOk();
        $this->getJson('/api/personal/surveys')->assertOk();
        $this->getJson('/api/personal/activity?type=saves')->assertOk();
        $this->getJson('/api/personal/incoming-surveys')->assertForbidden();
        $this->getJson('/api/customer/profile')->assertForbidden();
        $this->getJson('/api/agent/listings')->assertForbidden();
    }

    public function test_agent_inbox_scopes_thread_membership_and_surveys_to_current_branch(): void
    {
        $agent=$this->account('agent');
        $peer=$this->account('customer');
        $otherAgent=$this->account('agent');
        $started=\App\Models\Conversation::create(['type'=>'direct','created_by'=>$agent->id,'status'=>'active']);
        $incoming=\App\Models\Conversation::create(['type'=>'direct','created_by'=>$peer->id,'status'=>'active']);
        $outside=\App\Models\Conversation::create(['type'=>'direct','created_by'=>$peer->id,'status'=>'active']);
        foreach ([[$started,$agent,$otherAgent],[$incoming,$agent,$peer],[$outside,$otherAgent,$peer]] as [$thread,$a,$b]) {
            foreach ([$a,$b] as $member) $thread->members()->create(['user_id'=>$member->id,'member_role'=>$member->role->name,'joined_at'=>now()]);
        }
        $owned=$this->listing($agent,'QA Own Listing');
        $unowned=$this->listing($otherAgent,'QA Unowned Listing');
        foreach ([$owned,$unowned] as $listing) {
            \App\Models\SurveyRequest::create(['listing_id'=>$listing->id,'agent_id'=>$agent->id,'customer_id'=>$peer->id,
                'status'=>'pending','requested_at'=>now()->addDay()]);
        }
        Sanctum::actingAs($agent);
        $this->getJson('/api/personal/conversations?scope=started')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$started->id);
        $this->getJson('/api/personal/conversations?scope=incoming')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$incoming->id);
        $this->getJson('/api/personal/conversations/'.$incoming->id.'/messages')->assertOk()->assertJsonPath('can_reply',false);
        $this->getJson('/api/personal/conversations/'.$outside->id.'/messages')->assertNotFound();
        $this->getJson('/api/personal/incoming-surveys')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.listing.id',$owned->id);
    }
    public function test_admin_directories_create_inactive_accounts_and_record_sanitized_audit_events(): void
    {
        \Illuminate\Support\Facades\Schema::create('audit_logs', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('action');
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->actingAs($this->account('admin'));
        $this->get('/admin/customers')->assertOk()->assertSee('Data Customer');
        $this->get('/admin/agent-data')->assertOk()->assertSee('QA Batam');
        $legacy = $this->account('customer');
        $legacy->update(['email'=>'LEGACY@example.test']);
        $legacy->delete();
        $this->post('/admin/directory/customer', ['name'=>'Duplicate', 'email'=>'legacy@example.test'])
            ->assertSessionHasErrors('email');
        foreach (['customer'=>'customers', 'agent'=>'agent-data'] as $kind=>$section) {
            $email = "directory-$kind@example.test";
            $attributes = ['name'=>"Directory $kind", 'email'=>strtoupper($email)];
            if ($kind==='agent') $attributes['branch_id'] = $this->branch->id;
            $this->post("/admin/directory/$kind", [...$attributes, 'role'=>'owner'])->assertSessionHasErrors('role');
            $this->post("/admin/directory/$kind", $attributes)->assertRedirect("/admin/$section");
            $target = User::where('email',$email)->sole();
            $this->assertTrue($target->hasRole($kind));
            $this->assertSame('inactive', $target->status);
            $this->assertNull($target->password);
            $this->assertNull($target->email_verified_at);
            if ($kind==='agent') $this->assertSame('pending', $target->agentProfile->verification_status);
            $this->post("/admin/directory/$kind", $attributes)->assertSessionHasErrors('email');
            $this->get("/admin/$section?q=$email")->assertOk()->assertSee($email);
            $edit = ['name'=>'Nama diperbarui', 'status'=>'active'];
            $this->post("/admin/directory/$kind/$target->id", $edit)->assertSessionHasErrors('confirm_status');
            $this->post("/admin/directory/$kind/$target->id", [...$edit, 'confirm_status'=>'1'])->assertRedirect("/admin/$section");
            $this->assertSame('active', $target->fresh()->status);
            $target->createToken('directory-qa');
            $this->actingAs($target)->post("/admin/directory/$kind/$target->id", $edit)->assertForbidden();
            $this->actingAs($this->account('admin'));
            $this->post("/admin/directory/$kind/$target->id",
                [...$edit, 'status'=>'inactive', 'confirm_status'=>'1'])->assertRedirect("/admin/$section");
            $this->assertSame('inactive', $target->fresh()->status);
            $this->assertCount(0, $target->tokens()->get());
        }
        $owner = $this->account('owner');
        $this->post("/admin/directory/customer/$owner->id", ['name'=>'Do not change', 'status'=>'active'])->assertNotFound();
        $this->actingAs($owner)->get('/admin/agent-data')->assertForbidden();
        $records = \App\Models\AuditLog::orderBy('id')->get();
        $this->assertCount(6, $records);
        foreach ($records as $record) {
            $this->assertTrue(app(\App\Services\SecurityAudit::class)->authentic($record));
            $this->assertArrayNotHasKey('email', $record->new_values);
            $this->assertArrayNotHasKey('name', $record->new_values);
        }
    }
    public function test_workspace_role_boundaries_and_guest_redirects():void
    {
        foreach(['/branch','/admin','/owner']as$url)$this->get($url)->assertRedirect('/?panel=profile');
        foreach(['customer','agent','branch_manager','admin','owner']as$role){
            $this->actingAs($this->account($role));
            foreach(['branch'=>'branch_manager','admin'=>'admin','owner'=>'owner']as$path=>$allowed){
                $response=$this->get('/'.$path);$role===$allowed?$response->assertOk():$response->assertForbidden();
            }
            if ($role!=='admin') {
                $target = $this->account('customer');
                $this->post(route('admin.users.password-reset', $target), ['confirm_reset'=>1])->assertForbidden();
            }
        }
    }
    public function test_admin_can_request_private_reset_for_managed_verified_accounts_only(): void
    {
        \Illuminate\Support\Facades\Schema::create('password_reset_tokens', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        \Illuminate\Support\Facades\Notification::fake();
        $admin = $this->account('admin');
        $admin->update(['email'=>'managed-admin@example.test','password'=>'synthetic-admin-reauth']);
        \Illuminate\Support\Facades\Schema::create('audit_logs', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('action');
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $this->actingAs($admin);
        foreach (['customer', 'agent', 'branch_manager'] as $role) {
            $target = $this->account($role);
            $target->forceFill(['email'=>"$role@example.test", 'email_verified_at'=>now(), 'password'=>'synthetic-old-password'])->save();
            $originalHash = $target->getAuthPassword();
            $url = route('admin.users.password-reset',$target);
            $this->post($url, ['confirm_reset'=>1, 'current_password'=>'synthetic-admin-reauth', 'email'=>'unexpected@example.test'])->assertRedirect('/admin/users')
                ->assertSessionHasNoErrors();
            \Illuminate\Support\Facades\Notification::assertSentTo($target, \Illuminate\Auth\Notifications\ResetPassword::class);
            $this->assertTrue(hash_equals($originalHash, $target->fresh()->getAuthPassword()));
            $entry = \App\Models\AuditLog::where('action','auth.managed_reset_requested')->latest('id')->firstOrFail();
            $this->assertSame($admin->id, $entry->user_id);
            $this->assertSame($target->id, $entry->new_values['subject_user_id']);
            $this->assertArrayNotHasKey('password', $entry->new_values);
        }
        foreach ([$admin, $this->account('owner')] as $protected) {
            $this->post(route('admin.users.password-reset',$protected), ['confirm_reset'=>1])->assertForbidden();
            \Illuminate\Support\Facades\Notification::assertNotSentTo($protected, \Illuminate\Auth\Notifications\ResetPassword::class);
        }
    }

    public function test_managed_reset_rejects_missing_confirmation_unverified_email_and_log_fallback(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->actingAs($this->account('admin'));
        $target = $this->account('customer');
        $target->update(['email'=>'unverified@example.test']);
        $url = route('admin.users.password-reset',$target);
        $this->post($url)->assertSessionHasErrors('reset');
        $this->post($url,['confirm_reset'=>1])->assertSessionHasErrors('reset');
        $target->forceFill(['email_verified_at'=>now()])->save();
        $this->post($url,['confirm_reset'=>1])->assertSessionHasErrors('current_password');
        $this->post($url,['confirm_reset'=>1,'current_password'=>'incorrect-synthetic'])->assertSessionHasErrors('reset');
        config(['mail.default'=>'failover']);
        $this->post($url,['confirm_reset'=>1])->assertSessionHasErrors('reset');
        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }
    public function test_manager_queries_cannot_escape_branch_with_query_parameters():void
    {
        $other=Branch::create(['agency_id'=>$this->branch->agency_id,'name'=>'Other branch','status'=>'active']);
        $own=$this->account('agent');$foreign=$this->account('agent',$other);
        $this->listing($own,'VISIBLE LOCAL');$this->listing($foreign,'PRIVATE FOREIGN');
        $this->actingAs($this->account('branch_manager'))->get('/branch/listings?branch_id='.$other->id)
            ->assertOk()->assertSee('VISIBLE LOCAL')->assertDontSee('PRIVATE FOREIGN');
        $this->get('/branch/agents?branch_id='.$other->id)->assertOk()->assertSee('Synthetic agent');
        $this->get('/branch/users')->assertForbidden();
    }
    public function test_inactive_branch_and_revoked_permission_are_denied():void
    {
        $manager=$this->account('branch_manager');$this->actingAs($manager);
        $manager->role->permissions()->detach();$manager->unsetRelation('role');$this->get('/branch')->assertForbidden();
        (new PromertySeeder)->run();$manager->unsetRelation('role');$this->branch->update(['status'=>'inactive']);$manager->unsetRelation('branch');
        $this->get('/branch')->assertForbidden();
    }
    public function test_admin_user_changes_persist_but_owner_and_admin_targets_are_protected():void
    {
        $admin=$this->account('admin');$customer=$this->account('customer');$owner=$this->account('owner');
        $this->actingAs($admin)->post('/admin/users/'.$customer->id.'/status',['status'=>'suspended'])->assertRedirect('/admin/users');
        $this->assertSame('suspended',$customer->fresh()->status);
        foreach([$owner,$admin]as$target)$this->post('/admin/users/'.$target->id.'/status',['status'=>'inactive'])->assertForbidden();
        $this->assertSame('active',$owner->fresh()->status);
        $this->actingAs($owner)->get('/admin/users')->assertForbidden();
    }
    public function test_admin_branch_creation_and_agent_verification_are_real_writes():void
    {
        $admin=$this->account('admin');$agent=$this->account('agent');$agent->agentProfile->update(['verification_status'=>'pending']);
        $this->actingAs($admin)->post('/admin/branches/create',['name'=>'New QA branch','agency_id'=>$this->branch->agency_id])->assertRedirect();
        $this->assertDatabaseHas('branches',['name'=>'New QA branch','status'=>'active']);
        $this->post('/admin/agents/'.$agent->id.'/verify',['verification_note'=>'QA checked'])->assertRedirect('/admin/agents');
        $this->assertSame('verified',$agent->agentProfile->fresh()->verification_status);
        $this->post('/admin/agents/'.$agent->id.'/verify',['verification_note'=>['bad']])->assertSessionHasErrors('verification_note');
    }
    public function test_owner_reports_are_aggregate_only_and_audit_respects_activation():void
    {
        $agent=$this->account('agent');$this->listing($agent,'NOT AN OWNER PRIVATE DETAIL');
        $this->actingAs($this->account('owner'))->get('/owner/reports')->assertOk()->assertSee('QA Batam')->assertDontSee('NOT AN OWNER PRIVATE DETAIL');
        $this->get('/owner/audit')->assertForbidden();
        $this->actingAs($this->account('admin'))->get('/admin/audit')->assertOk()->assertSee('belum diaktifkan');
    }
    public function test_owner_period_filter_accepts_end_only_and_rejects_reversed_range():void
    {
        $this->actingAs($this->account('owner'))->get('/owner/reports?to=2026-11-22')->assertOk()
            ->assertViewHas('metrics',fn($metrics)=>$metrics['Cabang']===1);
        $this->getJson('/owner/reports?from=2026-11-22&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }
    public function test_agent_filtering_and_ordering_preserve_owned_summary():void
    {
        $agent=$this->account('agent');$this->listing($agent,'Zulu QA');$this->listing($agent,'Alpha QA');
        Sanctum::actingAs($agent);
        $this->getJson('/api/agent/listings?sort=title&q=Alpha')->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.title','Alpha QA')->assertJsonPath('summary.total',2)->assertJsonPath('identity.branch','QA Batam');
        $this->getJson('/api/agent/listings?status=broken')->assertUnprocessable();
    }
    public function test_admin_agent_creation_uses_existing_workflow_without_role_escalation():void
    {
        $this->actingAs($this->account('admin'))->post('/admin/agents/create',['name'=>'QA onboard','email'=>'qa-onboard@example.test',
            'branch_id'=>$this->branch->id,'role_id'=>Role::where('name','owner')->value('id')])->assertRedirect('/admin/agents');
        $user=User::where('email','qa-onboard@example.test')->firstOrFail();
        $this->assertSame('agent',$user->role->name);$this->assertSame('pending',$user->agentProfile->verification_status);
        $this->actingAs($this->account('branch_manager'))->post('/admin/agents/create',[])->assertForbidden();
    }
    public function test_content_moderation_persists_and_is_not_an_owner_privilege():void
    {
        $admin=$this->account('admin');$agent=$this->account('agent');$listing=$this->listing($agent,'Moderation QA');
        $post=app(AgentListingWorkflow::class)->post($listing);$post->update(['status'=>'published','published_at'=>now()]);$listing->update(['status'=>'active']);
        $this->actingAs($admin)->get('/admin/content')->assertOk()->assertSee('Moderasi konten');
        $this->actingAs($admin)->post('/admin/content/'.$post->id.'/moderate',['moderation_status'=>'removed','moderation_reason'=>'Synthetic policy check'])->assertRedirect('/admin/content');
        $this->assertSame('removed',$post->fresh()->moderation_status);$this->assertFalse(app(\App\Services\FeedService::class)->visible()->whereKey($post->id)->exists());
        $this->actingAs($this->account('owner'))->post('/admin/content/'.$post->id.'/moderate',['moderation_status'=>'active','moderation_reason'=>'deny'])->assertForbidden();
        $this->get('/owner/content')->assertForbidden();
        $admin->role->permissions()->detach(\App\Models\Permission::where('name','restore_content')->value('id'));$admin->unsetRelation('role');
        $this->actingAs($admin)->post('/admin/content/'.$post->id.'/moderate',['moderation_status'=>'active','moderation_reason'=>'deny restore'])->assertForbidden();
    }
    public function test_administrative_writes_roll_back_when_required_audit_fails():void
    {
        $admin=$this->account('admin');$customer=$this->account('customer');
        (require base_path('database/migrations/2026_11_18_000001_create_security_identities_table.php'))->up();
        config(['security.qa_rollout'=>true]);
        $sessionRequest=\Illuminate\Http\Request::create('/');$sessionRequest->setLaravelSession(app('session')->driver());
        app(\App\Services\SessionSecurity::class)->stamp($sessionRequest,$admin);$this->withSession($sessionRequest->session()->all());
        $this->partialMock(\App\Services\SecurityAudit::class)->shouldReceive('record')->andThrow(new \RuntimeException('Synthetic audit unavailable'));
        $this->actingAs($admin)->withoutExceptionHandling();
        $before=Branch::count();
        foreach([['/admin/branches/create',['name'=>'ROLLBACK QA','agency_id'=>$this->branch->agency_id]],
            ['/admin/users/'.$customer->id.'/status',['status'=>'suspended']]]as[$url,$data]){
            try{$this->post($url,$data);$this->fail('Required audit must fail closed.');}
            catch(\RuntimeException $e){$this->assertSame('Synthetic audit unavailable',$e->getMessage());}
        }
        $this->assertSame($before,Branch::count());$this->assertSame('active',$customer->fresh()->status);
    }
    public function test_media_order_requires_exact_owned_set_and_editable_lifecycle():void
    {
        $agent=$this->account('agent');$listing=$this->listing($agent,'Order QA');$post=app(AgentListingWorkflow::class)->post($listing);
        $a=$post->media()->create(['media_type'=>'photo','file_url'=>'test/a.jpg','sort_order'=>1]);
        $b=$post->media()->create(['media_type'=>'photo','file_url'=>'test/b.jpg','sort_order'=>2]);Sanctum::actingAs($agent);
        $url='/api/agent/listings/'.$listing->id.'/media-order';
        $this->patchJson($url,['media_ids'=>[$b->id,$a->id]])->assertOk();
        $this->assertSame([$b->id,$a->id],$post->media()->pluck('id')->all());
        $this->patchJson($url,['media_ids'=>[$a->id,99999]])->assertUnprocessable();
        $this->patchJson($url,['media_ids'=>[$a->id,$a->id]])->assertUnprocessable();
        $listing->update(['status'=>'active']);$this->patchJson($url,['media_ids'=>[$a->id,$b->id]])->assertStatus(409);
        Sanctum::actingAs($this->account('agent'));$this->patchJson($url,['media_ids'=>[$a->id,$b->id]])->assertNotFound();
    }
    public function test_agent_activity_is_permission_gated_and_never_includes_another_agent():void
    {
        $agent=$this->account('agent');$other=$this->account('agent');$workflow=app(AgentListingWorkflow::class);
        $own=$workflow->post($this->listing($agent,'Own activity'));$foreign=$workflow->post($this->listing($other,'Foreign activity'));
        foreach([$own,$foreign] as$post)\App\Models\Like::create(['user_id'=>$agent->id,'post_id'=>$post->id]);
        Sanctum::actingAs($agent);$this->getJson('/api/agent/listings')->assertOk()->assertJsonPath('activity.likes',1);
        $agent->role->permissions()->detach(\App\Models\Permission::where('name','view_own_analytics')->value('id'));
        $this->getJson('/api/agent/listings')->assertOk()->assertJsonPath('activity',null);
    }
    public function test_stale_customer_object_cannot_be_used_to_suspend_a_new_owner():void
    {
        $admin=$this->account('admin');$target=$this->account('customer');$target->load('role');
        \Illuminate\Support\Facades\DB::table('users')->where('id',$target->id)->update(['role_id'=>Role::where('name','owner')->value('id')]);
        $request=\Illuminate\Http\Request::create('/admin/users/'.$target->id.'/status','POST',['status'=>'suspended']);$request->setUserResolver(fn()=>$admin);
        try{app(\App\Http\Controllers\Web\OperationsWorkspaceController::class)->userStatus($request,$target);$this->fail('Protected role changed after binding.');}
        catch(\Illuminate\Auth\Access\AuthorizationException){$this->assertTrue(true);}
        $this->assertSame('active',$target->fresh()->status);
    }
    public function test_stale_post_object_cannot_bypass_restore_permission():void
    {
        $admin=$this->account('admin');$agent=$this->account('agent');$post=app(AgentListingWorkflow::class)->post($this->listing($agent,'Stale moderation QA'));
        $admin->role->permissions()->detach(\App\Models\Permission::where('name','restore_content')->value('id'));
        \Illuminate\Support\Facades\DB::table('posts')->where('id',$post->id)->update(['moderation_status'=>'removed']);
        $request=\Illuminate\Http\Request::create('/admin/content/'.$post->id.'/moderate','POST',['moderation_status'=>'active','moderation_reason'=>'denied stale restore']);$request->setUserResolver(fn()=>$admin);
        try{app(\App\Http\Controllers\Web\OperationsWorkspaceController::class)->moderate($request,$post);$this->fail('Restore must use locked current status.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertSame('removed',$post->fresh()->moderation_status);
    }
}
