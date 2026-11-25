<?php

namespace Tests\Feature;

use App\Models\{Notification, Permission, Role, User};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FeedDatabase;
use Tests\TestCase;

class AccountPagesTest extends TestCase
{
    use FeedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFeedDatabase();
    }

    public function test_help_search_and_role_permission_filtering(): void
    {
        $this->get('/help?q=reset+password&category=account')->assertOk()
            ->assertSee('Bagaimana reset password?')->assertDontSee('Bagaimana mencari properti?');
        $this->get('/help?category=admin')->assertOk()->assertDontSee('Bagaimana meninjau operasi?');
        $this->get('/help?q=%3Cscript%3E')->assertOk()->assertSee('Belum ada jawaban')
            ->assertDontSee('value="<script>"',false);

        $role = Role::create(['name'=>'admin','level'=>4]);
        $admin = $this->userFor($role,'admin');
        $this->actingAs($admin,'web')->get('/help?category=admin')->assertOk()
            ->assertDontSee('Bagaimana meninjau operasi?');
        $role->permissions()->attach(Permission::create(['name'=>'view_operational_analytics']));
        $this->get('/help?category=admin')->assertOk()->assertSee('Bagaimana meninjau operasi?')
            ->assertDontSee('Bagaimana melihat laporan?');
    }

    public function test_unread_preference_filters_only_the_current_customers_notifications(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('type');
            $table->string('title');
            $table->string('message')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $role = Role::create(['name'=>'customer','level'=>1]);
        $viewer = $this->userFor($role,'viewer');
        $other = $this->userFor($role,'another');
        foreach ([[$viewer,'Unread fixture',null],[$viewer,'Read fixture',now()],[$other,'Other fixture',null]] as [$user,$title,$readAt]) {
            Notification::create(['user_id'=>$user->id,'type'=>'test','title'=>$title,'read_at'=>$readAt]);
        }
        $this->actingAs($viewer,'web')->get('/settings')->assertOk()->assertSee('Hanya yang belum dibaca');
        $this->getJson('/api/customer/notifications')->assertOk()->assertJsonCount(2,'data');
        $this->getJson('/api/customer/notifications?unread=1')->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.title','Unread fixture')->assertDontSee('Other fixture');
        $this->getJson('/api/customer/notifications?unread=malformed')->assertUnprocessable();
        $this->post('/web-auth/logout')->assertRedirect('/');
        $this->assertGuest('web');
    }

    public function test_standard_seeder_provisions_role_catalog_without_example_accounts(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->assertSame(['admin','agent','branch_manager','customer','owner'],Role::orderBy('name')->pluck('name')->all());
        $this->assertDatabaseCount('users',0);
    }

    private function userFor(Role $role,string $name): User
    {
        return User::create(['name'=>$name,'email'=>"$name@example.test",'status'=>'active',
            'password'=>\Illuminate\Support\Str::random(32),'role_id'=>$role->id]);
    }
}
