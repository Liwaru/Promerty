<?php
namespace Tests\Feature;
use App\Models\{Agency,AgentProfile,Branch,Developer,Listing,Location,Permission,Project,ProjectAssignment,Role,User};
use App\Services\{AgentListingWorkflow,ProjectAssignments,FeedService};
use Database\Seeders\PromertySeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FeedDatabase;
use Tests\TestCase;
class PrimaryProjectAssignmentTest extends TestCase
{
    use FeedDatabase;use \Tests\Support\ProjectDatabase;
    private Branch $branch;private Branch $other;private Project $project;private User $admin;
    protected function setUp():void
    {
        parent::setUp();$this->createFeedDatabase();(new PromertySeeder)->run();
        $this->createProjectFixtureSchema();
        $agency=Agency::create(['name'=>'QA','slug'=>'primary-qa']);
        $this->branch=Branch::create(['name'=>'Branch A','agency_id'=>$agency->id,'status'=>'active']);
        $this->other=Branch::create(['name'=>'Branch B','agency_id'=>$agency->id,'status'=>'active']);
        $this->admin=$this->account('admin');
        $developer=Developer::create(['name'=>'Synthetic developer','created_by'=>$this->admin->id]);
        $location=Location::create(['label'=>'Bengkong','city'=>'Batam','address'=>'PRIVATE_PROJECT_ADDRESS','visibility'=>'approximate','created_by'=>$this->admin->id]);
        $this->project=Project::create(['name'=>'QA assigned project','developer_id'=>$developer->id,'location_id'=>$location->id,'status'=>'active','created_by'=>$this->admin->id]);
    }
    private function account(string $role,?Branch $branch=null):User
    {
        $user=User::create(['name'=>'QA '.$role,'role_id'=>Role::where('name',$role)->value('id'),'branch_id'=>($branch??$this->branch)->id,'status'=>'active']);
        if($role==='agent')AgentProfile::create(['user_id'=>$user->id,'verification_status'=>'verified']);return $user;
    }
    private function payload():array
    {
        $category=\App\Models\PropertyCategory::firstOrCreate(['slug'=>'rumah'],['name'=>'Rumah']);
        return ['listing_type'=>'primary','project_id'=>$this->project->id,'title'=>'QA Primary unit','category_id'=>$category->id,
            'transaction_type'=>'sale','price'=>'1250000000','price_period'=>'total'];
    }
    public function test_agent_and_branch_assignments_do_not_cross_branches():void
    {
        $a=$this->account('agent');$b=$this->account('agent');$c=$this->account('agent',$this->other);$svc=app(ProjectAssignments::class);
        $svc->assign($this->admin,$this->project,$this->branch,$a);
        $this->assertSame(1,$svc->projects($a)->count());$this->assertSame(0,$svc->projects($b)->count());$this->assertSame(0,$svc->projects($c)->count());
        $svc->assign($this->admin,$this->project,$this->branch,null);
        $this->assertSame(1,$svc->projects($b)->count());$this->assertSame(0,$svc->projects($c)->count());
        $this->project->update(['status'=>'inactive']);$this->assertSame(0,$svc->projects($a)->count());
    }
    public function test_assigned_primary_lifecycle_uses_project_without_private_address_leak():void
    {
        $agent=$this->account('agent');app(ProjectAssignments::class)->assign($this->admin,$this->project,$this->branch,$agent);Sanctum::actingAs($agent);
        $id=$this->postJson('/api/agent/listings',$this->payload())->assertCreated()->assertJsonPath('data.listing_type','primary')->json('data.id');
        $listing=Listing::findOrFail($id);$this->assertNull($listing->secondary_property_id);
        $workflow=app(AgentListingWorkflow::class);$post=$workflow->post($listing);
        $media=$post->media()->create(['media_type'=>'photo','file_url'=>'fixture/photo.jpg','sort_order'=>1]);
        $this->postJson('/api/agent/listings/'.$id.'/publish')->assertUnprocessable()->assertJsonValidationErrors('media');
        $media->update(['media_type'=>'image']);
        $this->postJson('/api/agent/listings/'.$id.'/publish')->assertOk()->assertJsonPath('data.address',null);
        $this->assertTrue(app(FeedService::class)->visible()->whereKey($post->id)->exists());
        $this->getJson('/api/posts/'.$post->id)->assertOk()->assertDontSee('PRIVATE_PROJECT_ADDRESS');
        $this->postJson('/api/agent/listings/'.$id.'/archive')->assertOk();
        $this->assertFalse(app(FeedService::class)->visible()->whereKey($post->id)->exists());
    }
    public function test_unassigned_id_and_client_private_address_are_rejected():void
    {
        $agent=$this->account('agent',$this->other);Sanctum::actingAs($agent);
        $this->postJson('/api/agent/listings',$this->payload())->assertNotFound();$this->assertSame(0,Listing::count());
        $this->postJson('/api/agent/listings',[...$this->payload(),'address'=>'overwrite project'])->assertUnprocessable();
    }
    public function test_revocation_and_agent_branch_transfer_prevent_future_primary_writes():void
    {
        $agent=$this->account('agent');$svc=app(ProjectAssignments::class);$grant=$svc->assign($this->admin,$this->project,$this->branch,$agent);Sanctum::actingAs($agent);
        $id=$this->postJson('/api/agent/listings',$this->payload())->assertCreated()->json('data.id');$svc->revoke($this->admin,$grant);
        $this->patchJson('/api/agent/listings/'.$id,$this->payload())->assertNotFound();
        $this->postJson('/api/agent/listings/'.$id.'/publish')->assertNotFound();
        $this->postJson('/api/agent/listings/'.$id.'/archive')->assertOk();
        $svc->assign($this->admin,$this->project,$this->branch,$agent);$agent->update(['branch_id'=>$this->other->id]);$agent->unsetRelation('branch');
        $this->assertSame(0,$svc->projects($agent)->count());
    }
    public function test_management_requires_explicit_permission_and_manager_branch_scope():void
    {
        $manager=$this->account('branch_manager');$owner=$this->account('owner');
        $this->actingAs($manager)->post('/branch/projects/'.$this->project->id.'/assign',['branch_id'=>$this->branch->id])->assertForbidden();
        $this->actingAs($owner)->post('/owner/projects/'.$this->project->id.'/assign',['branch_id'=>$this->branch->id])->assertForbidden();
        $permission=Permission::where('name','manage_project_assignments')->firstOrFail();$manager->role->permissions()->attach($permission);$manager->unsetRelation('role');
        $this->actingAs($manager)->post('/branch/projects/'.$this->project->id.'/assign',['branch_id'=>$this->other->id])->assertForbidden();
        $this->post('/branch/projects/'.$this->project->id.'/assign',['branch_id'=>$this->branch->id])->assertRedirect();
        $this->assertSame(1,ProjectAssignment::where('active',true)->count());
        $foreign=app(ProjectAssignments::class)->assign($this->admin,$this->project,$this->other,null);
        $this->post('/branch/assignments/'.$foreign->id.'/revoke')->assertForbidden();
        $owner->role->permissions()->attach($permission);$owner->unsetRelation('role');
        $this->actingAs($owner)->post('/owner/projects/'.$this->project->id.'/assign',['branch_id'=>$this->other->id])->assertRedirect();
    }
    public function test_agent_must_belong_to_selected_branch_and_grants_are_idempotent():void
    {
        $agent=$this->account('agent',$this->other);$this->actingAs($this->admin)
            ->post('/admin/projects/'.$this->project->id.'/assign',['branch_id'=>$this->branch->id,'agent_id'=>$agent->id])->assertStatus(422);
        for($i=0;$i<2;$i++)$this->post('/admin/projects/'.$this->project->id.'/assign',['branch_id'=>$this->other->id,'agent_id'=>$agent->id])->assertRedirect();
        $this->assertSame(1,ProjectAssignment::count());
    }
    public function test_assignment_audit_identifies_project_without_private_details():void
    {
        $agent=$this->account('agent');
        \Illuminate\Support\Facades\Schema::create('audit_logs',function(\Illuminate\Database\Schema\Blueprint $t){
            $t->id();$t->unsignedBigInteger('user_id')->nullable();$t->string('action');$t->text('new_values')->nullable();$t->timestamp('created_at')->nullable();
        });config(['security.qa_rollout'=>true]);
        $svc=app(ProjectAssignments::class);$grant=$svc->assign($this->admin,$this->project,$this->branch,$agent);$svc->revoke($this->admin,$grant);
        $rows=\App\Models\AuditLog::orderBy('id')->get();
        $this->assertSame(['project.assigned','project.revoked'],$rows->pluck('action')->all());
        foreach($rows as$row){$this->assertSame($this->project->id,$row->new_values['project_id']);
            $this->assertTrue(app(\App\Services\SecurityAudit::class)->authentic($row));}
        $this->assertStringNotContainsString('PRIVATE_PROJECT_ADDRESS',$rows->toJson());
    }
    public function test_stale_project_branch_agent_and_actor_are_revalidated_before_assignment():void
    {
        $agent=$this->account('agent');$db=\Illuminate\Support\Facades\DB::class;
        foreach([['projects',$this->project->id,'status','inactive','active',422],
            ['branches',$this->branch->id,'status','inactive','active',422],
            ['users',$agent->id,'branch_id',$this->other->id,$this->branch->id,422],
            ['users',$this->admin->id,'status','inactive','active',403]]as[$table,$id,$column,$invalid,$original,$code]){
            $db::table($table)->where('id',$id)->update([$column=>$invalid]);
            try{app(ProjectAssignments::class)->assign($this->admin,$this->project,$this->branch,$agent);$this->fail('Stale eligibility accepted.');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame($code,$e->getStatusCode());}
            $this->assertSame(0,ProjectAssignment::count());$db::table($table)->where('id',$id)->update([$column=>$original]);
        }
    }
    public function test_stale_assignment_cannot_be_revoked_after_moving_to_another_branch():void
    {
        $agent=$this->account('agent');$manager=$this->account('branch_manager');
        $manager->role->permissions()->attach(Permission::where('name','manage_project_assignments')->value('id'));
        $service=app(ProjectAssignments::class);$grant=$service->assign($this->admin,$this->project,$this->branch,$agent);
        $agent->update(['branch_id'=>$this->other->id]);$agent->unsetRelation('branch');
        $service->assign($this->admin,$this->project,$this->other,$agent);
        try{$service->revoke($manager,$grant);$this->fail('Old branch revoked another branch assignment.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertTrue($grant->fresh()->active);$this->assertSame($this->other->id,$grant->fresh()->branch_id);
    }
    public function test_admin_project_management_preserves_location_and_permission_boundaries():void
    {
        $agent=$this->account('agent');app(ProjectAssignments::class)->assign($this->admin,$this->project,$this->branch,$agent);
        $secondaryData=[...$this->payload(),'listing_type'=>'secondary','location_label'=>'Bengkong','address'=>'PRIVATE_SHARED_ADDRESS'];unset($secondaryData['project_id']);
        $secondary=app(AgentListingWorkflow::class)->save($agent,$secondaryData);
        $sourceLocation=$secondary->secondaryProperty->location_id;
        $this->actingAs($this->admin)->get('/admin/catalog')->assertOk()->assertSee('Tambah proyek');
        $this->post('/admin/catalog/create',['name'=>'Created QA project','developer_id'=>$this->project->developer_id,
            'location_id'=>$sourceLocation,'created_by'=>$agent->id])->assertRedirect('/admin/catalog');
        $this->assertDatabaseHas('projects',['name'=>'Created QA project','created_by'=>$this->admin->id,'status'=>'active']);
        $created=Project::where('name','Created QA project')->firstOrFail();
        $this->assertNotSame($sourceLocation,$created->location_id);$this->assertNull($created->location->address);
        $this->assertSame($secondary->id,app(AgentListingWorkflow::class)->save($agent,$secondaryData,$secondary->id)->id);
        $oldLocation=$this->project->location_id;
        $this->post('/admin/catalog/'.$this->project->id.'/update',['name'=>'Updated QA project','status'=>'inactive','location_id'=>99999])->assertRedirect();
        $this->assertSame($oldLocation,$this->project->fresh()->location_id);$this->assertSame(0,app(ProjectAssignments::class)->projects($agent)->count());
        $this->actingAs($this->account('owner'))->get('/admin/catalog')->assertForbidden();
        $this->post('/admin/catalog/create',['name'=>'denied'])->assertForbidden();
        $this->actingAs($this->account('branch_manager'))->post('/admin/catalog/'.$this->project->id.'/update',[])->assertForbidden();
    }
}
