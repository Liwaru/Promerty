<?php

namespace Tests\Feature;

use App\Models\{Role, User};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Auth, Hash, Notification, Password, Schema};
use Tests\Support\FeedDatabase;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use FeedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFeedDatabase();
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    private function account(): User
    {
        return User::create(['name'=>'Security fixture', 'email'=>'security@example.test',
            'password'=>Hash::make('fixture-password-only'), 'status'=>'active',
            'role_id'=>Role::create(['name'=>'customer','level'=>1])->id]);
    }

    private function forgetAuthentication(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_cookie_api_logout_invalidates_session_and_preserves_other_device_token(): void
    {
        $user = $this->account();
        $other = $user->createToken('other device')->plainTextToken;
        $this->postJson('/web-auth/login', ['email'=>$user->email,'password'=>'fixture-password-only'])->assertOk();
        $oldId = session()->getId();
        $oldCsrf = session()->token();
        $this->withHeader('Origin','http://localhost')->postJson('/api/auth/logout')->assertOk();
        $this->assertGuest('web');
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldCsrf, session()->token());
        $this->forgetAuthentication();
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/auth/logout')->assertUnauthorized();
        $this->withToken($other)->getJson('/api/me')->assertOk();
    }

    public function test_bearer_logout_revokes_only_the_authenticated_token_and_cannot_be_replayed(): void
    {
        $user = $this->account();
        $first = $user->createToken('first')->plainTextToken;
        $other = $user->createToken('other')->plainTextToken;
        $this->withToken($first)->postJson('/api/auth/logout')->assertOk();
        $this->assertSame(1,$user->tokens()->count());
        $this->forgetAuthentication();
        $this->withToken($first)->getJson('/api/me')->assertUnauthorized();
        $this->forgetAuthentication();
        $this->withToken($first)->postJson('/api/auth/logout')->assertUnauthorized();
        $this->forgetAuthentication();
        $this->withToken($other)->getJson('/api/me')->assertOk();
    }

    public function test_transient_auth_without_a_session_store_fails_closed_instead_of_throwing(): void
    {
        $this->actingAs($this->account(),'web')->postJson('/api/auth/logout')->assertUnauthorized();
    }

    public function test_expired_or_suspended_tokens_cannot_authenticate(): void
    {
        $user = $this->account();
        $expired = $user->createToken('expired',['*'],now()->subMinute())->plainTextToken;
        $this->withToken($expired)->getJson('/api/me')->assertUnauthorized();
        $valid = $user->createToken('valid')->plainTextToken;
        $user->update(['status'=>'suspended']);
        $this->forgetAuthentication();
        $this->withToken($valid)->getJson('/api/me')->assertForbidden();
        $this->assertSame(0,$user->tokens()->count());
    }

    public function test_password_reset_revokes_existing_bearer_tokens(): void
    {
        $user = $this->account();
        $old = $user->createToken('before reset')->plainTextToken;
        $token = Password::createToken($user);
        $this->post('/password/reset',['email'=>$user->email,'token'=>$token,
            'password'=>'replacement-fixture-password','password_confirmation'=>'replacement-fixture-password'])
            ->assertRedirect(route('password.reset.complete'));
        $this->assertSame(0,$user->tokens()->count());
        $this->forgetAuthentication();
        $this->withToken($old)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_recovery_broker_cooldown_does_not_reveal_account_existence(): void
    {
        Notification::fake();
        $user = $this->account();
        $this->postJson('/web-auth/password/forgot',['email'=>$user->email])->assertAccepted();
        $known = $this->postJson('/web-auth/password/forgot',['email'=>$user->email])->assertAccepted();
        $unknown = $this->postJson('/web-auth/password/forgot',['email'=>'absent@example.test'])->assertAccepted();
        $this->assertSame($known->json(),$unknown->json());
    }

    public function test_reset_invalidates_a_cookie_session_without_security_rollout_and_tokens_expire(): void
    {
        config(['security.qa_rollout'=>false]);
        $user = $this->account();
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertOk();
        $oldSession = session()->only([Auth::guard('web')->getName(), 'security.password_marker']);
        $old = Password::createToken($user);
        $this->travel(61)->minutes();
        $newPassword = 'replacement-fixture-password';
        $payload = ['email'=>$user->email,'token'=>$old,'password'=>$newPassword,'password_confirmation'=>$newPassword];
        $this->post('/password/reset',$payload)->assertSessionHasErrors('email');
        $token = Password::createToken($user);
        $this->post('/password/reset',[...$payload,'token'=>$token])->assertRedirect(route('password.reset.complete'));
        $this->get(route('password.reset.complete'))->assertOk();
        $this->forgetAuthentication();
        $this->withSession($oldSession)->getJson('/web-auth/me')->assertUnauthorized();
        $this->post('/password/reset',[...$payload,'token'=>$token])->assertSessionHasErrors('email');
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertUnprocessable();
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>$newPassword])->assertOk();
    }

    public function test_api_login_is_limited_across_case_variants_and_recovers_after_cooldown(): void
    {
        $this->freezeTime();
        $user = $this->account();
        for ($i=0;$i<5;$i++) {
            $this->postJson('/api/auth/login',['email'=>$i%2?strtoupper($user->email):$user->email,'password'=>'wrong'])
                ->assertUnprocessable();
            if ($i === 0) $this->travel(30)->seconds();
        }
        $this->postJson('/api/auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])
            ->assertStatus(429)->assertHeader('Retry-After','3570');
        $this->travel(3569)->seconds();
        $this->postJson('/api/auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])
            ->assertStatus(429)->assertHeader('Retry-After','1');
        $this->travel(1)->seconds();
        $this->postJson('/api/auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])
            ->assertOk()->assertJsonPath('user.id',$user->id);
        $this->postJson('/api/auth/login',['email'=>$user->email,'password'=>'wrong'])->assertUnprocessable();
        $this->postJson('/api/auth/login',['email'=>$user->email,'password'=>'wrong'])
            ->assertStatus(429)->assertHeader('Retry-After','30');
    }

    public function test_web_and_api_share_an_account_limit_across_ips_without_trusting_forwarded_ip(): void
    {
        $user = $this->account();
        for ($i=0;$i<5;$i++) {
            $this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.10'])
                ->postJson('/api/auth/login',['email'=>$user->email,'password'=>'wrong'])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.11'])
            ->postJson('/api/auth/login',['email'=>strtoupper($user->email),'password'=>'wrong'])->assertStatus(429);
        $this->postJson('/api/auth/login',['email'=>'different@example.test','password'=>'wrong'])->assertUnprocessable();
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertStatus(429);
        $request = \Illuminate\Http\Request::create('/','GET',server:[
            'REMOTE_ADDR'=>'192.0.2.50','HTTP_X_FORWARDED_FOR'=>'198.51.100.9']);
        $middleware = app(\Illuminate\Http\Middleware\TrustProxies::class);
        $middleware->handle($request,fn ($r) => response($r->ip()));
        $this->assertSame('192.0.2.50',$request->ip());
    }

    public function test_ip_limit_also_bounds_many_distinct_accounts(): void
    {
        for ($i=0;$i<30;$i++) {
            $this->postJson('/api/auth/login',['email'=>"absent{$i}@example.test",'password'=>'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/auth/login',['email'=>'next@example.test','password'=>'wrong'])->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.77'])
            ->postJson('/api/auth/login',['email'=>'next@example.test','password'=>'wrong'])->assertUnprocessable();
    }

    public function test_phone_identity_limits_normalize_equivalent_numbers_and_do_not_expose_pii_in_keys(): void
    {
        $request = \Illuminate\Http\Request::create('/api/auth/phone/login','POST',['phone'=>'08123456789']);
        $limiter = \Illuminate\Support\Facades\RateLimiter::limiter('auth-phone-login');
        $local = $limiter($request);
        $request->merge(['phone'=>'+62 812 345 6789']);
        $international = $limiter($request);
        $this->assertSame($local[1]->key,$international[1]->key);
        $this->assertStringNotContainsString('8123456789',$local[1]->key);
    }

    public function test_five_roles_share_the_same_budget_without_revoking_an_existing_session(): void
    {
        foreach (['customer','agent','branch_manager','admin','owner'] as $index => $role) {
            $this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.'.($index+1)]);
            $password = \Illuminate\Support\Str::random(32);
            $user = User::create([
                'name'=>'Role fixture','email'=>"$role@example.test",'status'=>'active',
                'password'=>Hash::make($password),'role_id'=>Role::create(['name'=>$role,'level'=>$index])->id,
            ]);
            $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>$password])->assertOk();
            for ($attempt=0;$attempt<5;$attempt++) {
                $this->postJson('/api/auth/login',['email'=>$user->email,'password'=>'incorrect-fixture'])
                    ->assertUnprocessable();
            }
            $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>$password])->assertStatus(429);
            $this->assertAuthenticatedAs($user,'web');
            $this->postJson('/web-auth/logout')->assertOk();
        }
    }

    public function test_unknown_email_has_identical_failure_and_throttle_responses(): void
    {
        $this->freezeTime();
        $user = $this->account();
        for ($attempt=1;$attempt<=6;$attempt++) {
            $known = $this->postJson('/api/auth/login',['email'=>$user->email,'password'=>'wrong']);
            $unknown = $this->postJson('/api/auth/login',['email'=>'missing@example.test','password'=>'wrong']);
            $known->assertStatus($attempt===6 ? 429 : 422);
            $this->assertSame($known->status(),$unknown->status());
            $this->assertSame($known->json(),$unknown->json());
            $this->assertSame($known->headers->get('Retry-After'),$unknown->headers->get('Retry-After'));
        }
    }

    public function test_in_flight_guesses_reserve_the_budget_before_password_verification(): void
    {
        $limiter = app(\App\Services\PasswordLoginAttempts::class);
        $evaluate = function (int $remaining) use (&$evaluate,$limiter): bool {
            return $limiter->check('parallel@example.test',function () use ($remaining,&$evaluate): bool {
                if ($remaining > 0) return $evaluate($remaining-1);
                return false;
            });
        };
        try {
            $evaluate(5);
            $this->fail('Sixth concurrent verification was admitted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(429,$error->getStatusCode());
        }
        $this->assertTrue($limiter->check('parallel@example.test',fn () => true));
    }

    public function test_unsupported_password_budget_cache_fails_closed(): void
    {
        config(['cache.password_login_store'=>'null']);
        $this->postJson('/web-auth/login',['email'=>'absent@example.test','password'=>'wrong'])
            ->assertServiceUnavailable()->assertJsonPath('message','Layanan masuk sementara tidak tersedia.');
        $this->assertGuest('web');
    }

    public function test_password_budget_works_with_database_cache_and_locks(): void
    {
        (require database_path('migrations/0001_01_01_000001_create_cache_table.php'))->up();
        config(['cache.password_login_store'=>'database']);
        $this->freezeTime();
        $user = $this->account();
        for ($attempt=0;$attempt<5;$attempt++) {
            $this->postJson('/api/auth/login',['email'=>$user->email,'password'=>'wrong-fixture'])
                ->assertUnprocessable();
        }
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])
            ->assertStatus(429)->assertHeader('Retry-After','3600');
        $this->assertDatabaseCount('cache',1);
        $this->assertDatabaseCount('cache_locks',0);
        $storedKey=\Illuminate\Support\Facades\DB::table('cache')->value('key');
        $this->assertStringNotContainsString($user->email,$storedKey);
        $this->travel(3600)->seconds();
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertOk();
        $this->assertDatabaseCount('cache',0);
        $this->assertDatabaseCount('cache_locks',0);
    }

    public function test_logout_all_describes_token_scope_and_does_not_claim_other_browser_sessions_are_revoked(): void
    {
        $user = $this->account();
        $first = $user->createToken('first')->plainTextToken;
        $other = $user->createToken('other')->plainTextToken;
        $this->withToken($first)->postJson('/api/auth/logout-all')->assertOk()
            ->assertJsonPath('revoked_scope','personal_access_tokens')
            ->assertJsonPath('other_browser_sessions_revoked',false);
        $this->assertSame(0,$user->tokens()->count());
        $this->forgetAuthentication();
        $this->withToken($other)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_session_auth_takes_precedence_over_an_unrelated_bearer_token(): void
    {
        $user = $this->account();
        $other = $user->createToken('do not revoke from cookie logout')->plainTextToken;
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertOk();
        $this->withHeader('Origin','http://localhost')->withToken($other)
            ->postJson('/api/auth/logout')->assertOk();
        $this->assertGuest('web');
        $this->assertSame(1,$user->tokens()->count());
    }

    public function test_dynamic_auth_responses_have_safe_headers_without_hsts_on_local_http(): void
    {
        $response = $this->getJson('/web-auth/me')->assertOk()
            ->assertHeader('X-Content-Type-Options','nosniff')
            ->assertHeader('X-Frame-Options','SAMEORIGIN')
            ->assertHeader('Referrer-Policy','same-origin');
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->get('/search')->assertOk()->assertHeader('Content-Security-Policy',
            "frame-src 'self' https://www.tiktok.com https://www.instagram.com; frame-ancestors 'self'; object-src 'none'; base-uri 'self'");
        $this->getJson('/api/customer/profile')->assertUnauthorized()->assertHeader('X-Content-Type-Options','nosniff');
    }

    public function test_database_session_expires_after_inactivity_in_isolated_sqlite(): void
    {
        Schema::create('sessions',function (Blueprint $table): void {
            $table->string('id')->primary();$table->integer('user_id')->nullable();
            $table->string('ip_address')->nullable();$table->text('user_agent')->nullable();
            $table->text('payload');$table->integer('last_activity');
        });
        config(['session.driver'=>'database','session.connection'=>'sqlite','session.lifetime'=>1]);
        $this->app['session']->forgetDrivers();
        $user = $this->account();
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertOk();
        $id = session()->getId();
        $this->assertDatabaseHas('sessions',['id'=>$id,'user_id'=>$user->id]);
        $this->travel(61)->seconds();
        $this->forgetAuthentication();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->withCookie(config('session.cookie'),$id)->getJson('/web-auth/me')->assertOk()->assertJsonPath('user',null);
        $this->withHeader('Origin','http://localhost')->postJson('/api/auth/logout')->assertUnauthorized();
    }
}
