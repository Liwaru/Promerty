<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Role, User, UserAuthProvider};
use App\Services\{SecurityAudit, SessionSecurity};
use GuzzleHttp\{Client, HandlerStack};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Auth, Schema};
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Tests\Support\FeedDatabase;
use Tests\TestCase;

class GoogleOAuthTest extends TestCase
{
    use FeedDatabase;
    private int $networkCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();$this->createFeedDatabase();
        config(['services.google'=>['client_id'=>'mock-client-only','client_secret'=>'mock-secret-only',
            'redirect'=>'http://localhost:8000/auth/google/callback'],'security.qa_rollout'=>false,'security.mfa_enabled'=>false]);
        foreach(['customer','agent','branch_manager','admin','owner']as$i=>$role)Role::create(['name'=>$role,'level'=>$i+1]);
    }
    private function user(string $role='customer',string $email='existing@example.test'):User
    {
        return User::create(['name'=>'Synthetic OAuth user','email'=>$email,'password'=>'synthetic-password',
            'status'=>'active','role_id'=>Role::where('name',$role)->value('id')]);
    }
    private function provider(array $claims=[],bool $networkFailure=false):void
    {
        $claims += ['sub'=>'1234567890','email'=>'google-new@example.test','email_verified'=>true,'name'=>'Synthetic Google Customer'];
        $factory=\Mockery::mock(\Laravel\Socialite\Contracts\Factory::class);
        $factory->shouldReceive('driver')->with('google')->andReturnUsing(function()use($claims,$networkFailure){
            $handler=new MockHandler($networkFailure ? [new \RuntimeException('PRIVATE_PROVIDER_TOKEN_SECRET')]:[
                new Response(200,[],json_encode(['access_token'=>'mock-access-token-only','refresh_token'=>'mock-refresh-token-only','expires_in'=>3600])),
                new Response(200,[],json_encode($claims))]);
            $stack=HandlerStack::create($handler);
            $stack->push(function($next){return function($request,$options)use($next){$this->networkCalls++;return $next($request,$options);};});
            return (new GoogleProvider(app('request'),'mock-client-only','mock-secret-only','http://localhost:8000/auth/google/callback'))
                ->setHttpClient(new Client(['handler'=>$stack]));
        });
        Socialite::swap($factory);
    }
    private function begin():string
    {
        $r=$this->get('/auth/google/redirect')->assertRedirect();parse_str(parse_url($r->headers->get('Location'),PHP_URL_QUERY),$query);
        $this->assertSame('http://localhost:8000/auth/google/callback',$query['redirect_uri']);
        $this->assertArrayNotHasKey('client_secret',$query);
        $this->assertStringContainsString('openid',$query['scope']);return $query['state'];
    }
    private function completeOAuth(string $state)
    {
        return $this->get('/auth/google/callback?'.http_build_query(['state'=>$state,'code'=>'mock-authorization-code-only']));
    }
    public function test_configured_redirect_is_stateful_and_unconfigured_modal_is_honest():void
    {
        $this->provider();$state=$this->begin();$this->assertSame($state,session('state'));
        $this->assertSame(0,$this->networkCalls);
        config(['services.google.client_secret'=>null]);
        $this->get('/')->assertOk()->assertSee('Login Google belum dikonfigurasi.')->assertSee('data-google-login',false);
        $this->get('/auth/google/redirect')->assertRedirect('/');$this->assertSame(0,$this->networkCalls);
    }
    public function test_installed_socialite_factory_builds_the_real_redirect_without_network():void
    {
        $response=$this->get('/auth/google/redirect')->assertRedirect();
        $url=$response->headers->get('Location');
        $this->assertSame('accounts.google.com',parse_url($url,PHP_URL_HOST));
        parse_str(parse_url($url,PHP_URL_QUERY),$query);
        $this->assertSame('http://localhost:8000/auth/google/callback',$query['redirect_uri']);
        $this->assertSame(session('state'),$query['state']);$this->assertArrayNotHasKey('client_secret',$query);
    }
    public function test_new_google_registration_is_customer_and_ignores_role_or_token_fields():void
    {
        $this->provider(['role'=>'owner','role_id'=>5]);$state=$this->begin();$old=session()->getId();
        $this->completeOAuth($state)->assertRedirect('/');$user=Auth::guard('web')->user();
        $this->assertTrue($user->hasRole('customer'));$this->assertNotNull($user->email_verified_at);$this->assertNull($user->password);
        $this->assertNotSame($old,session()->getId());$this->assertDatabaseHas('user_auth_providers',['user_id'=>$user->id,'provider_user_id'=>'1234567890']);
        $this->assertSame(2,$this->networkCalls);$this->assertNull(session('state'));$this->assertNull(session('google_oauth'));
        $json=json_encode([$user->toArray(),session()->all(),UserAuthProvider::first()->toArray()]);
        foreach(['mock-access-token-only','mock-refresh-token-only','mock-authorization-code-only','mock-secret-only']as$secret)$this->assertFalse(str_contains($json,$secret),'OAuth secrets must not persist.');
    }
    public function test_state_mismatch_expiry_and_replay_never_exchange_code():void
    {
        $this->provider();$state=$this->begin();$this->completeOAuth('wrong')->assertRedirect('/');$this->assertGuest();
        $this->completeOAuth($state)->assertRedirect('/');$this->assertSame(0,$this->networkCalls);
        $state=$this->begin();$this->travel(6)->minutes();$this->completeOAuth($state)->assertRedirect('/');$this->assertSame(0,$this->networkCalls);$this->assertSame(0,User::count());
    }
    public function test_cancel_and_provider_network_failure_are_sanitized():void
    {
        $this->provider([],true);$state=$this->begin();
        $this->get('/auth/google/callback?'.http_build_query(['state'=>$state,'error'=>'access_denied']))->assertRedirect('/');
        $this->assertSame(0,$this->networkCalls);$state=$this->begin();$this->completeOAuth($state)->assertRedirect('/');
        $this->assertGuest();$this->assertStringNotContainsString('PRIVATE_PROVIDER_TOKEN_SECRET',json_encode(session()->all()));$this->assertSame(0,User::count());
    }
    public function test_unverified_or_malformed_claims_fail_closed():void
    {
        foreach([['email_verified'=>false],['email_verified'=>'true'],['sub'=>''],['email'=>'bad'],['sub'=>['invalid']]]as$claims){
            $this->provider($claims);$state=$this->begin();$this->completeOAuth($state)->assertRedirect('/');$this->assertGuest();
            $this->assertSame(0,User::count());$this->assertSame(0,UserAuthProvider::count());
        }
    }
    public function test_existing_email_including_deleted_user_is_never_auto_linked():void
    {
        $user=$this->user('owner');$this->provider(['email'=>$user->email]);$state=$this->begin();$this->completeOAuth($state)->assertRedirect('/');
        $this->assertGuest();$this->assertTrue($user->fresh()->hasRole('owner'));$this->assertSame(0,UserAuthProvider::count());
        $user->delete();$state=$this->begin();$this->completeOAuth($state)->assertRedirect('/');$this->assertSame(1,User::withTrashed()->count());$this->assertSame(0,UserAuthProvider::count());
    }
    public function test_linked_five_roles_keep_role_and_cannot_gain_other_permissions():void
    {
        foreach(['customer','agent','branch_manager','admin','owner']as$i=>$role){
            $user=$this->user($role,$role.'@example.test');UserAuthProvider::create(['user_id'=>$user->id,'provider'=>'google','provider_user_id'=>(string)(100+$i)]);
            $this->provider(['sub'=>(string)(100+$i),'email'=>'changed-google-email@example.test','role'=>'admin']);
            $state=$this->begin();$this->completeOAuth($state)->assertRedirect('/');$this->assertAuthenticatedAs($user);
            $this->assertSame($role,$user->fresh()->role->name);$this->assertSame($role.'@example.test',$user->fresh()->email);
            $this->getJson('/web-auth/me')->assertOk()->assertJsonPath('user.id',$user->id)->assertJsonPath('user.role',$role);
            if($role==='agent')$this->get('/agent')->assertForbidden(); // Google does not verify an Agent profile.
            $this->get('/admin/catalog')->assertForbidden(); // None has operational permissions in this fixture.
            $this->postJson('/web-auth/logout')->assertOk();$this->app['auth']->forgetGuards();
            $this->getJson('/web-auth/me')->assertJsonPath('user',null);
        }
    }
    public function test_inactive_linked_account_is_denied():void
    {
        $user=$this->user();UserAuthProvider::create(['user_id'=>$user->id,'provider'=>'google','provider_user_id'=>'1234567890']);$user->update(['status'=>'inactive']);
        $this->provider();$state=$this->begin();$this->completeOAuth($state)->assertRedirect('/');$this->assertGuest();$this->assertSame(1,User::count());
    }
    public function test_linked_google_login_survives_local_password_throttling():void
    {
        $user=$this->user();
        UserAuthProvider::create(['user_id'=>$user->id,'provider'=>'google','provider_user_id'=>'1234567890']);
        for($attempt=0;$attempt<5;$attempt++){
            $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-wrong'])->assertUnprocessable();
        }
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'synthetic-password'])->assertStatus(429);
        $this->provider(['email'=>$user->email]);
        $this->completeOAuth($this->begin())->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1,User::count());
    }
    public function test_link_requires_authenticated_session_password_and_preserves_existing_role():void
    {
        $this->post('/auth/google/link',['password'=>'synthetic-password'])->assertRedirect();$this->assertSame(0,UserAuthProvider::count());
        $user=$this->user('owner');$this->actingAs($user)->postJson('/auth/google/link',['password'=>'wrong'])->assertUnprocessable();
        $this->provider(['email'=>'different-google@example.test']);
        $this->post('/auth/google/link',['password'=>'synthetic-password'])->assertRedirect();$state=session('state');
        $this->completeOAuth($state)->assertRedirect('/account/profile#connected-accounts');$this->assertAuthenticatedAs($user);$this->assertSame('owner',$user->fresh()->role->name);
        $this->assertDatabaseHas('user_auth_providers',['user_id'=>$user->id,'provider_user_id'=>'1234567890']);
        $this->get('/account/google')->assertRedirect('/account/profile#connected-accounts');
        $this->get('/account/profile')->assertOk()->assertSee('data-google-status="linked"', false);
    }
    public function test_link_cannot_take_identity_from_another_user_or_replace_existing_link():void
    {
        $first=$this->user('customer','first@example.test');$second=$this->user('admin','second@example.test');
        UserAuthProvider::create(['user_id'=>$first->id,'provider'=>'google','provider_user_id'=>'1234567890']);$this->provider();
        $this->actingAs($second)->post('/auth/google/link',['password'=>'synthetic-password'])->assertRedirect();$this->completeOAuth(session('state'))->assertRedirect('/account/profile#connected-accounts');
        $this->assertSame(1,UserAuthProvider::count());$this->assertDatabaseHas('user_auth_providers',['user_id'=>$first->id]);
        $this->actingAs($first)->post('/auth/google/link',['password'=>'synthetic-password'])->assertRedirect('/account/profile#connected-accounts');
        $this->assertSame(1,UserAuthProvider::count());
    }
    public function test_password_or_actor_change_during_link_invalidates_confirmation():void
    {
        $user=$this->user();$other=$this->user('customer','other@example.test');$this->provider();
        $this->actingAs($user)->post('/auth/google/link',['password'=>'synthetic-password']);$state=session('state');
        $this->actingAs($other);$this->completeOAuth($state)->assertRedirect('/account/profile#connected-accounts');$this->assertSame(0,$this->networkCalls);
        $this->actingAs($user)->post('/auth/google/link',['password'=>'synthetic-password']);$state=session('state');
        $user->update(['password'=>'changed-password']);$this->completeOAuth($state)->assertRedirect('/account/profile#connected-accounts');$this->assertSame(0,UserAuthProvider::count());
    }
    public function test_arbitrary_redirects_are_rejected_and_existing_password_login_is_unchanged():void
    {
        $this->provider();$this->get('/auth/google/redirect?return_to=https://evil.test');$this->assertSame('/',session('google_oauth.return_to'));
        $user=$this->user();$this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'synthetic-password'])->assertOk();
        $this->assertAuthenticatedAs($user);$this->postJson('/web-auth/logout')->assertOk();$this->assertGuest();
    }
    public function test_existing_api_cannot_bypass_no_email_merge_policy():void
    {
        $user=$this->user('admin');config(['promerty.google_client_id'=>'mock-client-only']);
        $client=\Mockery::mock(\Google\Client::class);$client->shouldReceive('setClientId')->once();
        $client->shouldReceive('verifyIdToken')->once()->andReturn(['sub'=>'api-new-sub','email'=>$user->email,'email_verified'=>true]);
        $this->app->instance(\Google\Client::class,$client);
        $this->postJson('/api/auth/google',['id_token'=>'mock-id-token-only'])->assertUnprocessable();
        $this->assertSame(0,UserAuthProvider::count());$this->assertSame(0,$user->tokens()->count());$this->assertTrue($user->fresh()->hasRole('admin'));
    }
    public function test_security_stamp_revocation_and_metadata_only_audit_remain_enforced():void
    {
        config(['security.qa_rollout'=>true]);
        (require base_path('database/migrations/2026_11_18_000001_create_security_identities_table.php'))->up();
        Schema::create('audit_logs',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id')->nullable();$t->string('action');$t->text('new_values')->nullable();$t->timestamp('created_at')->nullable();});
        $this->provider();$state=$this->begin();$this->completeOAuth($state)->assertRedirect('/');$user=Auth::guard('web')->user();
        $request=app('request');$this->assertTrue(app(SessionSecurity::class)->valid($request,$user));
        $this->assertSame(2,AuditLog::count());
        foreach(AuditLog::all()as$record){$this->assertTrue(app(SecurityAudit::class)->authentic($record));$this->assertStringNotContainsString('mock-',json_encode($record->new_values));}
        app(SessionSecurity::class)->revokeAll($user);$this->getJson('/web-auth/me')->assertUnauthorized();$this->assertGuest();
        $this->assertNull(session('google_oauth'));$this->completeOAuth($state)->assertRedirect('/');$this->assertGuest();
    }
    public function test_link_has_csrf_and_per_user_password_attempt_limits():void
    {
        $user=$this->user();$this->actingAs($user);
        $this->app['env']='local';
        $this->post('/auth/google/link',['password'=>'synthetic-password'])->assertStatus(419);
        $this->app['env']='testing';
        for($i=0;$i<5;$i++)$this->postJson('/auth/google/link',['password'=>'wrong'])->assertUnprocessable();
        $this->postJson('/auth/google/link',['password'=>'wrong'])->assertStatus(429);
        $this->assertSame(0,UserAuthProvider::count());
    }
    public function test_identity_uniqueness_and_audit_failure_do_not_leave_partial_accounts():void
    {
        $mock=\Mockery::mock(SecurityAudit::class)->makePartial();$mock->shouldReceive('record')->andThrow(new \RuntimeException('private-audit-failure'));
        $this->app->instance(SecurityAudit::class,$mock);
        $this->provider();$state=$this->begin();$this->completeOAuth($state)->assertRedirect('/');
        $this->assertSame(0,User::count());$this->assertSame(0,UserAuthProvider::count());$this->assertGuest();
    }
    public function test_callback_error_flash_is_escaped_and_open_redirect_values_are_rejected():void
    {
        $this->withSession(['google_oauth_message'=>'<script>bad()</script>'])->get('/')->assertOk()->assertDontSee('<script>bad()</script>',false);
        foreach(['//evil.test','/\\evil.test','/search?token=private','/auth/google/callback',"/search\n"]as$value)$this->assertSame('/',\App\Support\GoogleOAuth::returnPath($value));
        $this->assertSame('/posts/12',\App\Support\GoogleOAuth::returnPath('/posts/12'));
    }
    public function test_configured_modal_does_not_render_secrets_and_enables_google():void
    {
        $response=$this->get('/')->assertOk()
            ->assertDontSee('Akun baru melalui Google terdaftar sebagai Customer.')
            ->assertDontSee('auth-google-note')
            ->assertSee('Lanjutkan dengan Google')
            ->assertDontSee('href="/account/google"',false)
            ->assertDontSee('Pengaitan Google untuk akun existing')
            ->assertSee('Lupa password?')
            ->assertSee('Daftar sekarang')
            ->assertSee('data-google-url="/auth/google/redirect"',false);
        $this->assertDoesNotMatchRegularExpression('/<button\b[^>]*\bdata-google-login\b[^>]*\bdisabled\b[^>]*>/',$response->getContent());
        $this->assertFalse(str_contains($response->getContent(),'mock-secret-only'));
    }

    public function test_each_role_can_see_only_its_own_google_link_status_in_profile(): void
    {
        $this->get('/account/profile')->assertRedirect('/?panel=profile')->assertDontSee('Akun Terhubung');
        $this->get('/account/google')->assertRedirect('/?panel=profile');

        $other = $this->user('customer', 'another-profile@example.test');
        UserAuthProvider::create(['user_id' => $other->id, 'provider' => 'google', 'provider_user_id' => 'another-google-sub']);

        foreach (['customer', 'agent', 'branch_manager', 'admin', 'owner'] as $role) {
            $actor = $this->user($role, "$role-profile@example.test");
            $url = '/account/profile?user_id='.$other->id;
            $unlinked = $this->actingAs($actor)->get($url)->assertOk()
                ->assertSeeInOrder(['Keamanan Akun', 'Akun Terhubung', 'Google', 'Belum Terhubung'])
                ->assertSee('action="/auth/google/link"', false)
                ->assertSee('name="password" autocomplete="current-password"', false)
                ->assertSee('name="_token"', false)
                ->assertDontSee('another-google-sub')
                ->assertDontSee($other->email);
            $unlinked->assertSee('data-google-status="unlinked"', false);

            UserAuthProvider::create(['user_id' => $actor->id, 'provider' => 'google', 'provider_user_id' => "$role-private-sub"]);
            $this->get('/account/profile')->assertOk()
                ->assertSee('data-google-status="linked"', false)
                ->assertDontSee("$role-private-sub")
                ->assertDontSee('action="/auth/google/link"', false)
                ->assertDontSee('Putuskan Pengaitan');
            $this->assertSame($role, $actor->fresh()->role->name);
        }
    }
    public function test_state_missing_and_cancelled_callback_cannot_persist_query_secrets():void
    {
        $this->provider();
        $this->get('/auth/google/callback?code=mock-authorization-code-only')->assertRedirect('/')->assertHeader('Referrer-Policy','no-referrer');
        $this->assertFalse(str_contains(json_encode(session()->all()),'mock-authorization-code-only'));
        $this->assertSame(0,$this->networkCalls);
        $state=$this->begin();$this->get('/auth/google/callback?'.http_build_query(['state'=>$state,'error'=>'access_denied']))->assertRedirect('/');
        $this->assertSame(0,$this->networkCalls);$this->assertNull(session('state'));
    }
    public function test_existing_identity_table_enforces_both_uniqueness_constraints():void
    {
        $first=$this->user();$second=$this->user('customer','second@example.test');
        UserAuthProvider::create(['user_id'=>$first->id,'provider'=>'google','provider_user_id'=>'100']);
        foreach([['user_id'=>$second->id,'provider_user_id'=>'100'],['user_id'=>$first->id,'provider_user_id'=>'200']]as$row){
            try{UserAuthProvider::create($row+['provider'=>'google']);$this->fail('Duplicate provider identity accepted.');}
            catch(\Illuminate\Database\QueryException){$this->assertSame(1,UserAuthProvider::count());}
        }
    }
}
