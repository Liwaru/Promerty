<?php

namespace Tests\Feature;

use App\Models\{Listing,Permission,Role,User};
use App\Support\RoleCatalog;
use Database\Seeders\PromertySeeder;
use Illuminate\Support\Facades\{Gate,Hash};
use Tests\Support\FeedDatabase;
use Tests\TestCase;

class FiveRoleAuthenticationTest extends TestCase
{
    use FeedDatabase;
    protected function setUp():void {parent::setUp();$this->createFeedDatabase();(new PromertySeeder)->run();}
    private function account(string $role,?int $branch=null):User{return User::create(['name'=>'Test '.$role,'email'=>uniqid().'@example.test','role_id'=>Role::where('name',$role)->firstOrFail()->id,'branch_id'=>$branch,'password'=>Hash::make('testing-password'),'status'=>'active']);}
    public function test_five_roles_seed_without_deleting_or_changing_legacy_super_admin():void
    {
        $this->assertEqualsCanonicalizing(RoleCatalog::roles(),Role::pluck('name')->all());
        $legacy=Role::create(['name'=>'super_admin','level'=>5]);$legacy->permissions()->attach(Permission::where('name','manage_roles')->firstOrFail());
        (new PromertySeeder)->run();$this->assertSame(1,$legacy->permissions()->count());$this->assertSame(6,Role::count());
    }
    public function test_roles_without_an_accessible_dashboard_have_an_honest_fallback():void
    {
        foreach(RoleCatalog::roles() as $role){$user=$this->account($role);$destination=['admin'=>'/admin','owner'=>'/owner'][$role]??'/';$this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'wrong'])->assertUnprocessable();$this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'testing-password'])->assertOk()->assertJsonPath('user.role',$role)->assertJsonPath('redirect_to',$destination)->assertJsonPath('dashboard_available',in_array($role,['customer','admin','owner'],true));$this->getJson('/web-auth/me')->assertJsonPath('user.id',$user->id);$this->postJson('/web-auth/logout')->assertOk();$this->getJson('/web-auth/me')->assertJsonPath('user',null);}
    }
    public function test_registration_and_profile_cannot_escalate_role():void
    {
        $this->postJson('/web-auth/register',['name'=>'Test','email'=>'public@example.test','password'=>'testing-password','password_confirmation'=>'testing-password','role'=>'owner','role_id'=>Role::where('name','owner')->value('id')])->assertOk()->assertJsonPath('user.role','customer');
        $user=User::where('email','public@example.test')->firstOrFail();$this->actingAs($user)->patchJson('/api/me',['role_id'=>Role::where('name','admin')->value('id'),'name'=>'Renamed'])->assertOk();$this->assertTrue($user->fresh()->hasRole('customer'));
    }
    public function test_admin_owner_separation_is_enforced_even_with_legacy_owner_technical_grants():void
    {
        $owner=$this->account('owner');$admin=$this->account('admin');$owner->role->permissions()->attach(Permission::where('name','manage_system_settings')->firstOrFail());
        $this->assertFalse($owner->hasPermission('manage_system_settings'));$this->assertFalse($owner->hasPermission('verify_agents'));$this->assertTrue($owner->hasPermission('view_global_analytics'));$this->assertTrue($admin->hasPermission('verify_agents'));$this->assertFalse(Gate::forUser($admin)->allows('update',$owner));$this->assertFalse(Gate::forUser($admin)->allows('delete',$owner));
        $this->actingAs($owner)->getJson('/api/admin/agents/pending')->assertForbidden();$this->actingAs($admin)->getJson('/api/admin/agents/pending')->assertOk();
        $customer=$this->account('customer');$customer->role->permissions()->attach(Permission::where('name','verify_agents')->firstOrFail());
        $this->actingAs($customer)->getJson('/api/admin/agents/pending')->assertForbidden();
    }
    public function test_agent_listing_ownership_and_branch_manager_scope_are_server_policies():void
    {
        $agent=$this->account('agent');$other=$this->account('agent');$manager=$this->account('branch_manager');$agent->branch_id=10;$manager->branch_id=10;
        $own=new Listing(['agent_id'=>$agent->id,'branch_id'=>10]);$foreign=new Listing(['agent_id'=>$other->id,'branch_id'=>20]);
        $this->assertTrue(Gate::forUser($agent)->allows('update',$own));$this->assertFalse(Gate::forUser($agent)->allows('update',$foreign));$this->assertTrue(Gate::forUser($manager)->allows('viewInternal',$own));$this->assertFalse(Gate::forUser($manager)->allows('viewInternal',$foreign));$this->assertTrue(Gate::forUser($manager)->allows('view',$agent));$other->branch_id=20;$this->assertFalse(Gate::forUser($manager)->allows('view',$other));$this->assertFalse(Gate::forUser($manager)->allows('update',$own));
    }
    public function test_customer_cannot_access_internal_api_and_internal_roles_cannot_access_customer_workspace():void
    {
        $this->getJson('/api/admin/agents/pending')->assertUnauthorized();
        foreach(RoleCatalog::roles() as $role){$user=$this->account($role);$this->actingAs($user)->getJson('/api/customer/profile')->assertStatus($role==='customer'?200:403);if($role!=='admin')$this->getJson('/api/admin/agents/pending')->assertForbidden();$this->app['auth']->forgetGuards();}
    }

    public function test_head_requests_and_manipulated_owner_ids_do_not_bypass_authorization():void
    {
        $this->call('HEAD','/api/admin/agents/pending',server:['HTTP_ACCEPT'=>'application/json'])->assertUnauthorized();
        $owner=$this->account('owner');$admin=$this->account('admin');
        foreach(['customer','agent','branch_manager','owner'] as $role){
            $user=$role==='owner'?$owner:$this->account($role);
            $this->actingAs($user)->call('HEAD','/api/admin/agents/pending',server:['HTTP_ACCEPT'=>'application/json'])->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
        $this->actingAs($admin)->postJson('/api/admin/agents/'.$owner->id.'/verify',[
            'role'=>'agent','role_id'=>Role::where('name','agent')->value('id'),'branch_id'=>999,
        ])->assertUnprocessable();
        $this->assertTrue($owner->fresh()->hasRole('owner'));
        $this->assertNull($owner->fresh()->branch_id);
    }

    public function test_existing_token_uses_current_server_role_after_role_is_changed_in_test_fixture():void
    {
        $admin=$this->account('admin');$token=$admin->createToken('test current permissions')->plainTextToken;
        $this->withToken($token)->getJson('/api/admin/agents/pending')->assertOk();
        // Only this guarded SQLite fixture is changed; never an existing QA/main account.
        $admin->update(['role_id'=>Role::where('name','customer')->value('id')]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/admin/agents/pending')->assertForbidden();
    }
}
