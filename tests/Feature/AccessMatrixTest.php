<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Permission, Role, User};
use App\Services\SecurityAudit;
use Database\Seeders\PromertySeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FeedDatabase;
use Tests\TestCase;

final class AccessMatrixTest extends TestCase
{
    use FeedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFeedDatabase();
        (new PromertySeeder)->run();
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at');
        });
    }

    private function actor(string $name): User
    {
        return User::create(['name'=>'Synthetic '.$name,'role_id'=>Role::where('name',$name)->value('id'),'status'=>'active']);
    }

    public function test_revocation_and_rollback_are_scoped_atomic_and_audited(): void
    {
        $admin=$this->actor('admin');
        $agent=$this->actor('agent');
        $this->actingAs($admin);
        $page=$this->get('/admin/access?role=agent&category=Agent')->assertOk()->assertSee('Matriks izin');
        $revision=$page->viewData('revisions')['agent'];
        $data=['changes'=>['create_listings'=>false],'revision'=>$revision,'confirm_change'=>'1'];
        $this->patch('/admin/access/agent',$data)->assertRedirect();
        $this->assertFalse($agent->hasPermission('create_listings'));
        $this->assertTrue($agent->hasPermission('manage_own_listings'));
        $entry=AuditLog::sole();
        $this->assertSame($admin->id,$entry->user_id);
        $this->assertContains('create_listings',$entry->new_values['permissions_before']);
        $this->assertNotContains('create_listings',$entry->new_values['permissions_after']);
        $this->assertTrue(app(SecurityAudit::class)->authentic($entry));
        $this->patchJson('/admin/access/agent',$data)->assertUnprocessable();
        $this->post('/admin/access/history/'.$entry->id.'/rollback',['confirm_change'=>'1'])->assertRedirect();
        $this->assertTrue($agent->hasPermission('create_listings'));
        $this->postJson('/admin/access/history/'.$entry->id.'/rollback',['confirm_change'=>'1'])->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs',2);
    }

    public function test_admin_cannot_change_privileged_roles_or_sensitive_permissions(): void
    {
        $admin=$this->actor('admin');
        $this->actingAs($admin);
        $page=$this->get('/admin/access')->assertOk()->assertSee('Kelola Penugasan Proyek');
        $revision=$page->viewData('revisions')['agent'];
        $payload=['changes'=>['manage_project_assignments'=>true],'revision'=>$revision,'confirm_change'=>'1'];
        foreach (['agent','admin','owner','super_admin'] as $role) $this->patchJson('/admin/access/'.$role,$payload)->assertForbidden();
        $this->patchJson('/admin/access/agent',[...$payload,'changes'=>['create_listings'=>false],'confirm_change'=>'0'])->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs',0);
        $this->assertDatabaseMissing('role_permissions',['role_id'=>Role::where('name','agent')->value('id'),
            'permission_id'=>Permission::where('name','manage_project_assignments')->value('id')]);
    }

    public function test_browser_routes_require_admin_and_explicit_access_permission(): void
    {
        foreach (['customer','agent','branch_manager','owner'] as $name) {
            $this->actingAs($this->actor($name))->get('/admin/access')->assertForbidden();
        }
        $admin=$this->actor('admin');
        $admin->role->permissions()->detach(Permission::where('name','manage_permissions')->value('id'));
        $this->actingAs($admin)->get('/admin/access')->assertForbidden();
    }
}
