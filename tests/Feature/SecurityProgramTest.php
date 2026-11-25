<?php

namespace Tests\Feature;

use App\Contracts\OtpDelivery;
use App\Models\{AuditLog,Role,SecurityIdentity,User};
use App\Services\{OtpService,QaMfa,SecurityAudit,SessionSecurity,Totp};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache,Hash,Log,Schema};
use Illuminate\Validation\ValidationException;
use Tests\Support\FeedDatabase;
use Tests\TestCase;

class SecurityProgramTest extends TestCase
{
    use FeedDatabase;
    protected function setUp(): void
    {
        parent::setUp();$this->createFeedDatabase();config(['security.qa_rollout'=>true]);
        (require base_path('database/migrations/2026_11_18_000001_create_security_identities_table.php'))->up();
        Schema::create('audit_logs',function(Blueprint $t):void{
            $t->id();$t->unsignedBigInteger('user_id')->nullable();$t->string('action');$t->string('target_type')->nullable();
            $t->unsignedBigInteger('target_id')->nullable();$t->text('old_values')->nullable();$t->text('new_values')->nullable();
            $t->string('ip_address')->nullable();$t->text('user_agent')->nullable();$t->timestamp('created_at')->nullable();
        });
    }
    private function account(string $role='admin'):User
    {
        return User::create(['name'=>'Synthetic security QA','email'=>bin2hex(random_bytes(4)).'@example.test','password'=>'fixture-password-only',
            'status'=>'active','role_id'=>Role::firstOrCreate(['name'=>$role],['level'=>['customer'=>1,'agent'=>2,'branch_manager'=>3,'admin'=>4,'owner'=>6][$role]])->id]);
    }
    private function delivery(): OtpDelivery
    {
        $fake=new class implements OtpDelivery {
            public string $code='';public bool $fail=false;
            public function available():bool{return true;}
            public function send(string $phone,string $purpose,string $code):void{if($this->fail)throw new \RuntimeException('private provider diagnostic');$this->code=$code;}
        };
        $this->app->instance(OtpDelivery::class,$fake);return $fake;
    }
    private function rejected(callable $fn):void
    {
        try{$fn();$this->fail('Expected validation denial.');}catch(ValidationException){$this->assertTrue(true);}
    }
    public function test_public_phone_login_does_not_disclose_account_or_challenge_state(): void
    {
        $fake=$this->delivery();$active=$this->account('customer');
        $active->update(['phone'=>'+628000000081']);
        $inactive=$this->account('customer');$inactive->update(['phone'=>'+628000000082','status'=>'inactive']);
        app(OtpService::class)->issue($active->phone,'login');
        $wrong=$fake->code==='111111'?'222222':'111111';$baseline=null;
        foreach([$active->phone,$inactive->phone,'+628000000083'] as $phone){
            $response=$this->postJson('/api/auth/phone/login',['phone'=>$phone,'otp'=>$wrong])->assertUnprocessable();
            if($baseline===null)$baseline=$response->json();else $this->assertSame($baseline,$response->json());
        }
        $this->assertSame('Nomor HP atau OTP tidak valid.',$baseline['errors']['phone'][0]);
        $this->postJson('/api/auth/phone/login',['phone'=>$active->phone,'otp'=>$fake->code])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/auth/phone/login',['phone'=>$active->phone,'otp'=>$fake->code])->assertUnprocessable()->assertExactJson($baseline);
    }
    public function test_public_phone_registration_hides_existing_number_until_proof(): void
    {
        $this->delivery();$user=$this->account('customer');$user->update(['phone'=>'+628000000084']);
        $a=$this->postJson('/api/auth/phone/register',['phone'=>$user->phone,'otp'=>'111111','name'=>'Synthetic'])->assertUnprocessable();
        $b=$this->postJson('/api/auth/phone/register',['phone'=>'+628000000085','otp'=>'111111','name'=>'Synthetic'])->assertUnprocessable();
        $this->assertSame($a->json(),$b->json());
    }
    public function test_public_request_audit_has_no_recipient_or_delivery_claim(): void
    {
        $this->delivery();
        $this->postJson('/api/auth/phone/otp',['phone'=>'+628000000086','purpose'=>'login'])->assertOk();
        $this->postJson('/web-auth/password/forgot',['email'=>'synthetic-private@example.test'])->assertStatus(202);
        foreach(['auth.otp_requested','auth.recovery_requested'] as $event){
            $row=AuditLog::where('action',$event)->firstOrFail();
            $this->assertNull($row->user_id);$this->assertSame('requested',$row->new_values['outcome']);
            $this->assertTrue(app(SecurityAudit::class)->authentic($row));
        }
        $json=AuditLog::all()->toJson();$this->assertStringNotContainsString('628000000086',$json);
        $this->assertStringNotContainsString('synthetic-private',$json);
    }
    public function test_recovery_provider_failure_is_generic_and_diagnostics_are_not_logged(): void
    {
        Log::spy();config(['mail.default'=>'smtp']);
        \Illuminate\Support\Facades\Password::shouldReceive('sendResetLink')->twice()
            ->andThrow(new \RuntimeException('PRIVATE_RESET_URL_AND_TOKEN'));
        $a=$this->postJson('/web-auth/password/forgot',['email'=>'synthetic-known@example.test'])->assertStatus(202);
        $b=$this->postJson('/web-auth/password/forgot',['email'=>'synthetic-unknown@example.test'])->assertStatus(202);
        $this->assertSame($a->json(),$b->json());
        Log::shouldHaveReceived('warning')->with('security.recovery_delivery_failed')->twice();
        $this->assertStringNotContainsString('PRIVATE_RESET_URL_AND_TOKEN',$a->getContent());
    }
    public function test_otp_uses_test_transport_hashes_code_and_never_logs_it():void
    {
        Log::spy();$fake=$this->delivery();$otp=app(OtpService::class);$otp->issue('+628000000001','verify');
        $key='promerty:otp:'.hash('sha256','verify|+628000000001');$payload=Cache::get($key);
        $this->assertTrue(Hash::check($fake->code,$payload['hash']));$this->assertFalse(str_contains(json_encode($payload),$fake->code));
        $otp->verify('+628000000001','verify',$fake->code);$this->assertNull(Cache::get($key));
        $this->rejected(fn()=>$otp->verify('+628000000001','verify',$fake->code));Log::shouldNotHaveReceived('info');
    }
    public function test_otp_expiry_attempt_limit_resend_and_purpose_isolation():void
    {
        $fake=$this->delivery();$otp=app(OtpService::class);$otp->issue('+628000000002','login');
        $this->rejected(fn()=>$otp->issue('+628000000002','login'));
        $this->rejected(fn()=>$otp->verify('+628000000002','register',$fake->code));
        for($i=0;$i<5;$i++)$this->rejected(fn()=>$otp->verify('+628000000002','login','bad'));
        $this->rejected(fn()=>$otp->verify('+628000000002','login',$fake->code));
        $this->travel(61)->seconds();$otp->issue('+628000000002','login');$this->travel(6)->minutes();
        $this->rejected(fn()=>$otp->verify('+628000000002','login',$fake->code));
    }
    public function test_otp_unconfigured_or_failed_provider_does_not_claim_delivery():void
    {
        $user=$this->account('customer');
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertOk();
        $this->withHeader('Origin','http://localhost')->postJson('/api/feed/phone/otp',['phone'=>'08123456789'])->assertStatus(503);
        $fake=$this->delivery();$fake->fail=true;
        try{app(OtpService::class)->issue('+628000000003','verify');$this->fail('Expected service unavailable.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(503,$e->getStatusCode());$this->assertFalse(str_contains($e->getMessage(),'private provider'));}
        $this->assertNull(Cache::get('promerty:otp:'.hash('sha256','verify|+628000000003')));
    }
    public function test_session_revocation_invalidates_both_devices_only_for_the_target_user():void
    {
        $user=$this->account('customer');$other=$this->account('customer');$s=app(SessionSecurity::class);
        $first=Request::create('/');$first->setLaravelSession(app('session')->driver('array'));
        $second=Request::create('/');$second->setLaravelSession(new \Illuminate\Session\Store('device2',new \Illuminate\Session\ArraySessionHandler(120)));
        $third=Request::create('/');$third->setLaravelSession(new \Illuminate\Session\Store('device3',new \Illuminate\Session\ArraySessionHandler(120)));
        $s->stamp($first,$user);$s->stamp($second,$user);$s->stamp($third,$other);
        $token=$user->createToken('synthetic')->plainTextToken;$other->createToken('other');
        $this->assertTrue($s->valid($first,$user));$this->assertTrue($s->valid($second,$user));
        $s->revokeAll($user);$this->assertFalse($s->valid($first,$user));$this->assertFalse($s->valid($second,$user));
        $this->assertTrue($s->valid($third,$other));$this->assertSame(0,$user->tokens()->count());$this->assertSame(1,$other->tokens()->count());
        $this->app['auth']->forgetGuards();$this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }
    public function test_password_change_and_missing_stamp_fail_closed():void
    {
        $user=$this->account();$request=Request::create('/');$request->setLaravelSession(app('session')->driver('array'));$s=app(SessionSecurity::class);
        $this->assertFalse($s->valid($request,$user));$s->stamp($request,$user);$this->assertTrue($s->valid($request,$user));
        $user->update(['password'=>'changed-fixture-password']);$this->assertFalse($s->valid($request,$user));
    }
    public function test_database_session_cookie_replay_after_logout_all_is_rejected():void
    {
        Schema::create('sessions',function(Blueprint $t):void{$t->string('id')->primary();$t->integer('user_id')->nullable();$t->string('ip_address')->nullable();$t->text('user_agent')->nullable();$t->text('payload');$t->integer('last_activity');});
        config(['session.driver'=>'database','session.connection'=>'sqlite']);
        $browser=function(?string $id):void{
            $this->app['auth']->forgetGuards();$this->app['auth']->shouldUse('web');$this->app['session']->forgetDrivers();$this->app->forgetInstance('session.store');
            $this->defaultCookies=[];$this->withCredentials();if($id!==null)$this->withCookie(config('session.cookie'),$id);
        };
        $user=$this->account('customer');$browser(null);
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertOk();$one=session()->getId();
        $browser(null);$this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertOk();$two=session()->getId();
        $this->assertNotSame($one,$two);
        $browser($one);$this->postJson('/web-auth/logout')->assertOk();
        $browser($two);$this->getJson('/web-auth/me')->assertOk()->assertJsonPath('user.id',$user->id);
        $browser($one);$this->getJson('/web-auth/me')->assertOk()->assertJsonPath('user',null);
        $this->withHeader('Origin','http://localhost')->getJson('/api/customer/profile')->assertUnauthorized();
        $browser(null);$this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertOk();$one=session()->getId();
        $browser($one);$this->withHeader('Origin','http://localhost')->postJson('/api/auth/logout-all')->assertOk()->assertJsonPath('other_browser_sessions_revoked',true);
        $browser($two);$this->getJson('/web-auth/me')->assertUnauthorized();
        $browser($one);$this->withHeader('Origin','http://localhost')->getJson('/api/customer/profile')->assertUnauthorized();
        $browser(null);$this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertOk();
    }
    public function test_qa_logout_all_http_response_and_new_login_session():void
    {
        $user=$this->account('customer');
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertOk();
        $this->withHeader('Origin','http://localhost')->postJson('/api/auth/logout-all')->assertOk()->assertJsonPath('other_browser_sessions_revoked',true);
        $this->app['auth']->forgetGuards();$this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertOk();
    }
    public function test_totp_matches_public_rfc6238_sha1_vectors():void
    {
        $totp=app(Totp::class);$secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        foreach([59=>'94287082',1111111109=>'07081804',1111111111=>'14050471',1234567890=>'89005924',2000000000=>'69279037',20000000000=>'65353130']as$time=>$code)
            $this->assertSame($code,$totp->code($secret,intdiv($time,30),8));
    }
    public function test_mfa_confirm_encryption_replay_and_recovery_single_use():void
    {
        config(['security.mfa_enabled'=>true]); // In-memory foundation regression only.
        $user=$this->account();$mfa=app(QaMfa::class);$totp=app(Totp::class);$secret=$mfa->begin($user,'fixture-password-only');
        $raw=SecurityIdentity::find($user->id);$this->assertFalse(str_contains($raw->getRawOriginal('mfa_secret'),$secret));
        $this->assertFalse(str_contains($raw->toJson(),$secret));
        $codes=$mfa->confirm($user,$totp->code($secret,intdiv(now()->timestamp,30)));
        $this->assertCount(8,$codes);$this->assertFalse(str_contains(json_encode(SecurityIdentity::find($user->id)->getRawOriginal()),$codes[0]));
        $this->rejected(fn()=>$mfa->verify($user,$totp->code($secret,intdiv(now()->timestamp,30))));
        $mfa->verify($user,$codes[0]);$this->rejected(fn()=>$mfa->verify($user,$codes[0]));
    }
    public function test_mfa_is_disabled_for_every_role_even_with_security_rollout_enabled(): void
    {
        $this->assertFalse(config('security.mfa_enabled'));
        foreach (['customer', 'agent', 'branch_manager', 'admin', 'owner'] as $role) {
            $user = $this->account($role);
            try {
                app(QaMfa::class)->begin($user, 'fixture-password-only');
                $this->fail('MFA enrollment must stay disabled.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
            $this->assertFalse(SecurityIdentity::whereKey($user->id)->exists());
        }
    }
    public function test_disabled_mfa_does_not_challenge_existing_enrollment_or_remove_it(): void
    {
        $user = $this->account('owner');
        $state = app(SessionSecurity::class)->state($user);
        $state->update(['mfa_confirmed_at' => now()]);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'fixture-password-only'])->assertOk();
        $this->postJson('/web-auth/login', ['email' => $user->email, 'password' => 'fixture-password-only'])->assertOk();
        $this->assertNotNull($state->fresh()->mfa_confirmed_at);
    }
    public function test_mfa_blocks_api_and_web_password_login_without_challenge():void
    {
        config(['security.mfa_enabled'=>true]); // In-memory foundation regression only.
        $user=$this->account('owner');$mfa=app(QaMfa::class);$secret=$mfa->begin($user,'fixture-password-only');
        $mfa->confirm($user,app(Totp::class)->code($secret,intdiv(now()->timestamp,30)));$this->travel(61)->seconds();
        $this->postJson('/api/auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertUnprocessable();
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>'fixture-password-only'])->assertUnprocessable();
        $this->assertGuest('web');
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/login',['email'=>$user->email,'password'=>'fixture-password-only','mfa_code'=>app(Totp::class)->code($secret,intdiv(now()->timestamp,30))])->assertOk();
    }
    public function test_mfa_enrollment_requires_password_and_customer_cannot_enroll():void
    {
        config(['security.mfa_enabled'=>true]); // In-memory foundation regression only.
        $user=$this->account();$mfa=app(QaMfa::class);$this->rejected(fn()=>$mfa->begin($user,'incorrect'));
        $customer=$this->account('customer');
        try{$mfa->begin($customer,'fixture-password-only');$this->fail('Expected forbidden.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
    }
    public function test_mfa_pending_expiry_and_bruteforce_limit():void
    {
        config(['security.mfa_enabled'=>true]); // In-memory foundation regression only.
        $user=$this->account();$mfa=app(QaMfa::class);$secret=$mfa->begin($user,'fixture-password-only');$this->travel(11)->minutes();
        $this->rejected(fn()=>$mfa->confirm($user,app(Totp::class)->code($secret,intdiv(now()->timestamp,30))));
        for($i=0;$i<6;$i++)$this->rejected(fn()=>$mfa->verify($user,'bad'));
    }
    public function test_audit_redaction_signature_and_policy():void
    {
        $user=$this->account();$audit=app(SecurityAudit::class);$audit->record('auth.login',$user,['channel'=>'web','password'=>'PRIVATE_MARKER','otp'=>'PRIVATE_MARKER']);
        $record=AuditLog::firstOrFail();$this->assertFalse(str_contains($record->toJson(),'PRIVATE_MARKER'));$this->assertTrue($audit->authentic($record));
        $payload=$record->new_values;$payload['channel']='api';$record->new_values=$payload;$this->assertFalse($audit->authentic($record));
        $policy=new \App\Policies\AuditLogPolicy();$this->assertFalse($policy->viewAny($user));
        $permission=\App\Models\Permission::create(['name'=>'view_audit_logs']);$user->role->permissions()->attach($permission);
        $this->assertTrue($policy->viewAny($user));
        foreach(['owner','customer','agent','branch_manager']as$role)$this->assertFalse($policy->viewAny($this->account($role)));
    }
    public function test_qa_rollout_rejects_operational_database_configuration_before_queries():void
    {
        config(['database.default'=>'mysql','database.connections.mysql.database'=>'promerty']);
        try{\App\Support\SecurityRollout::enabled();$this->fail('Expected refusal.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(503,$e->getStatusCode());}
    }
    public function test_mfa_disable_requires_both_factors_and_revokes_old_credentials():void
    {
        config(['security.mfa_enabled'=>true]); // In-memory foundation regression only.
        $user=$this->account();$mfa=app(QaMfa::class);$secret=$mfa->begin($user,'fixture-password-only');
        $codes=$mfa->confirm($user,app(Totp::class)->code($secret,intdiv(now()->timestamp,30)));
        $user->createToken('after enrollment');$this->travel(61)->seconds();
        $this->rejected(fn()=>$mfa->disable($user,'wrong',$codes[0]));
        $mfa->disable($user,'fixture-password-only',$codes[0]);
        $this->assertNull(SecurityIdentity::find($user->id)->mfa_secret);$this->assertSame(0,$user->tokens()->count());
    }
    public function test_audit_retention_prunes_only_expired_security_events_in_qa():void
    {
        $user=$this->account();app(SecurityAudit::class)->record('auth.login',$user);
        $old=AuditLog::firstOrFail();$old->created_at=now()->subDays(91);$old->save();
        AuditLog::create(['action'=>'business.fixture','created_at'=>now()->subDays(100)]);
        app(SecurityAudit::class)->record('auth.logout',$user);
        $this->assertSame(1,app(\App\Services\AuditRetention::class)->pruneQa());
        $this->assertSame(2,AuditLog::count());$this->assertSame(1,AuditLog::where('action','business.fixture')->count());
    }
    public function test_audit_sensitive_account_updates_never_record_changed_values():void
    {
        $user=$this->account();$this->actingAs($user,'web');
        $user->update(['password'=>'changed-fixture-password','bio'=>'PRIVATE_MARKER']);
        $record=AuditLog::where('action','account.password_changed')->firstOrFail();
        $this->assertSame($user->id,$record->new_values['subject_user_id']);
        $this->assertFalse(str_contains($record->toJson(),'PRIVATE_MARKER'));
        $this->assertFalse(str_contains($record->toJson(),'changed-fixture-password'));
        $this->assertTrue(app(SecurityAudit::class)->authentic($record));
        $user->update(['status'=>'suspended']);$this->assertSame(1,AuditLog::where('action','account.access_changed')->count());
    }
    public function test_reset_link_is_not_sent_through_general_log_mailer():void
    {
        config(['mail.default'=>'log']);\Illuminate\Support\Facades\Notification::fake();$user=$this->account('customer');
        $this->postJson('/web-auth/password/forgot',['email'=>$user->email])->assertAccepted();
        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }
    public function test_authentication_codes_are_not_flashed_into_session_after_validation_error():void
    {
        $this->from('/')->post('/web-auth/login',['email'=>'invalid','password'=>'SYNTHETIC_MARKER',
            'mfa_code'=>'SYNTHETIC_MARKER','otp'=>'SYNTHETIC_MARKER'])->assertRedirect('/');
        foreach(['password','mfa_code','otp']as$key)$this->assertNull(session()->getOldInput($key));
    }
    public function test_mfa_payload_arrays_are_rejected_before_authentication_or_delivery():void
    {
        foreach(['/web-auth/login','/api/auth/login','/api/auth/google','/api/auth/phone/login']as$uri)
            $this->postJson($uri,['email'=>'synthetic@example.test','password'=>'synthetic-only',
                'mfa_code'=>['invalid']])->assertUnprocessable()->assertJsonValidationErrors('mfa_code');
    }
    public function test_upload_validation_requires_owner_and_valid_content_and_never_publishes():void
    {
        $owner=$this->account('agent');$other=$this->account('agent');
        $permission=\App\Models\Permission::create(['name'=>'manage_own_listings']);$owner->role->permissions()->attach($permission);
        $listing=\App\Models\Listing::create(['listing_code'=>'qa-upload','listing_type'=>'secondary','transaction_type'=>'sale',
            'agent_id'=>$owner->id,'branch_id'=>1,'category_id'=>1,'title'=>'Synthetic QA','price'=>1,'status'=>'draft']);
        $validator=app(\App\Services\PrivateMediaValidator::class);
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j0ioAAAAASUVORK5CYII=');
        $image=\Illuminate\Http\UploadedFile::fake()->image('fixture.png',1,1);
        $manifest=$validator->inspect($owner,$listing,$image);
        $this->assertSame(1,$manifest['width']);$this->assertTrue($manifest['quarantine_required']);$this->assertArrayNotHasKey('url',$manifest);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}\.png$/',$manifest['generated_name']);
        try{$validator->inspect($other,$listing,$image);$this->fail('Expected denial.');}catch(\Illuminate\Auth\Access\AuthorizationException){$this->assertTrue(true);}
        $bad=\Illuminate\Http\UploadedFile::fake()->createWithContent('fixture.jpg','<?php echo "synthetic";');
        $this->rejected(fn()=>$validator->inspect($owner,$listing,$bad));
        $path=\Illuminate\Http\UploadedFile::fake()->createWithContent('fixture.php',$png);
        $this->rejected(fn()=>$validator->inspect($owner,$listing,$path));
        $damaged=\Illuminate\Http\UploadedFile::fake()->createWithContent('damaged.png',$png);
        $this->rejected(fn()=>$validator->inspect($owner,$listing,$damaged));
    }
    public function test_rollout_rejects_connection_overrides_before_opening_any_connection():void
    {
        $original=config('database');
        foreach(['url'=>'sqlite:///not-memory.sqlite','mysql.unix_socket'=>'synthetic-socket',
            'mysql.read'=>['host'=>'other-host'],'mysql.write'=>['database'=>'promerty']]as$key=>$value){
            config(['database'=>$original]);
            if($key==='url')config(['database.connections.sqlite.url'=>$value]);
            else{
                $field=substr($key,6);
                $this->app->detectEnvironment(fn()=>'local');
                config(['database.default'=>'mysql','database.connections.mysql.host'=>'127.0.0.1',
                    'database.connections.mysql.port'=>'3306','database.connections.mysql.database'=>'promerty_qa',
                    'database.connections.mysql.url'=>null,'database.connections.mysql.'.$field=>$value]);
            }
            try{\App\Support\SecurityRollout::enabled();$this->fail('Expected isolation denial.');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(503,$e->getStatusCode());}
            $this->app->detectEnvironment(fn()=>'testing');
        }
        config(['database'=>$original]);
    }
    public function test_mfa_recovery_fails_if_enrollment_is_removed_between_read_and_consume():void
    {
        config(['security.mfa_enabled'=>true]); // In-memory foundation regression only.
        $user=$this->account();$mfa=app(QaMfa::class);$secret=$mfa->begin($user,'fixture-password-only');
        $codes=$mfa->confirm($user,app(Totp::class)->code($secret,intdiv(now()->timestamp,30)));
        $armed=true;
        \Illuminate\Support\Facades\DB::listen(function($query)use(&$armed,$user):void{
            if($armed&&str_starts_with(strtolower($query->sql),'select')&&str_contains($query->sql,'security_identities')){
                $armed=false;
                SecurityIdentity::whereKey($user->id)->update(['mfa_confirmed_at'=>null,'mfa_secret'=>null,'recovery_hashes'=>null]);
            }
        });
        $this->rejected(fn()=>$mfa->verify($user,$codes[0]));
        $this->assertFalse($armed);$this->assertNull(SecurityIdentity::find($user->id)->recovery_hashes);
    }
    public function test_password_reset_rejects_old_browser_stamp_bearer_and_reset_token_reuse():void
    {
        Schema::create('password_reset_tokens',function(Blueprint $t):void{$t->string('email')->primary();$t->string('token');$t->timestamp('created_at')->nullable();});
        $user=$this->account('customer');$session=Request::create('/');$session->setLaravelSession(app('session')->driver('array'));
        $security=app(SessionSecurity::class);$security->stamp($session,$user);
        $bearer=$user->createToken('before-reset')->plainTextToken;
        $token=\Illuminate\Support\Facades\Password::createToken($user);
        $payload=['email'=>$user->email,'token'=>$token,'password'=>'new-synthetic-password','password_confirmation'=>'new-synthetic-password'];
        $this->post('/password/reset',$payload)->assertRedirect(route('password.reset.complete'));
        $this->assertFalse($security->valid($session,$user->fresh()));$this->assertSame(0,$user->tokens()->count());
        $this->app['auth']->forgetGuards();$this->withToken($bearer)->getJson('/api/me')->assertUnauthorized();
        $this->post('/password/reset',$payload)->assertSessionHasErrors('email');
    }
    public function test_audit_outcomes_are_derived_from_events_and_not_untrusted_context():void
    {
        $audit=app(SecurityAudit::class);$user=$this->account();
        foreach(['auth.failed'=>'denied','mfa.enrollment_started'=>'pending','auth.logout_all'=>'success']as$event=>$outcome){
            $audit->record($event,$user,['outcome'=>'FORGED_SUCCESS','token'=>'PRIVATE_MARKER']);
            $row=AuditLog::where('action',$event)->firstOrFail();
            $this->assertSame($outcome,$row->new_values['outcome']);$this->assertTrue($audit->authentic($row));
            $this->assertFalse(str_contains($row->toJson(),'PRIVATE_MARKER'));
        }
    }
    public function test_password_failures_are_audited_without_request_credentials():void
    {
        $user=$this->account('customer');
        $wrong=\Illuminate\Support\Str::random(32);
        for($attempt=0;$attempt<5;$attempt++){
            $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>$wrong])->assertUnprocessable();
        }
        $this->postJson('/web-auth/login',['email'=>$user->email,'password'=>$wrong])->assertStatus(429);
        $logs=AuditLog::where('action','auth.failed')->get();
        $this->assertCount(5,$logs);
        foreach($logs as $record){
            $this->assertTrue(app(SecurityAudit::class)->authentic($record));
            $this->assertNull($record->user_id);
            $this->assertStringNotContainsString($user->email,$record->toJson());
            $this->assertStringNotContainsString($wrong,$record->toJson());
        }
    }
    private function auditAdmin(): User
    {
        $admin=$this->account('admin');
        $admin->role->permissions()->syncWithoutDetaching(\App\Models\Permission::firstOrCreate(['name'=>'view_audit_logs'])->id);
        return $admin;
    }
    public function test_role_denial_is_audited_without_request_secrets(): void
    {
        $user=$this->account('customer');\Laravel\Sanctum\Sanctum::actingAs($user);
        $this->getJson('/api/admin/agents/pending?token=PRIVATE_MARKER')->assertForbidden();
        $row=AuditLog::where('action','authorization.denied')->firstOrFail();
        $this->assertSame('role',$row->new_values['control']);$this->assertSame('denied',$row->new_values['outcome']);
        $this->assertTrue(app(SecurityAudit::class)->authentic($row));$this->assertStringNotContainsString('PRIVATE_MARKER',$row->toJson());
    }
    public function test_denial_audit_survives_the_controller_transaction_rollback(): void
    {
        $actor=$this->account('agent');$other=$this->account('agent');
        $agency=\App\Models\Agency::create(['name'=>'Synthetic audit agency','slug'=>'synthetic-audit']);
        $branch=\App\Models\Branch::create(['agency_id'=>$agency->id,'name'=>'Synthetic audit branch','status'=>'active']);
        $actor->update(['branch_id'=>$branch->id]);
        \App\Models\AgentProfile::create(['user_id'=>$actor->id,'verification_status'=>'verified']);
        $actor->role->permissions()->attach(\App\Models\Permission::create(['name'=>'manage_own_listings'])->id);
        $listing=\App\Models\Listing::create(['listing_code'=>'audit-other','listing_type'=>'secondary','agent_id'=>$other->id,
            'branch_id'=>$branch->id,'category_id'=>1,'title'=>'Synthetic protected listing','price'=>1,'status'=>'draft']);
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $this->postJson('/api/agent/listings/'.$listing->id.'/archive')->assertNotFound();
        $this->assertSame('draft',$listing->fresh()->status);
        $this->assertSame(0,\Illuminate\Support\Facades\DB::transactionLevel());
        $row=AuditLog::where('action','authorization.denied')->firstOrFail();
        $this->assertSame('ownership',$row->new_values['control']);$this->assertSame($actor->id,$row->user_id);
    }
    public function test_failed_denial_logging_does_not_mask_403_or_print_exception_details(): void
    {
        $user=$this->account('customer');Log::spy();
        \Illuminate\Support\Facades\DB::statement("CREATE TRIGGER qa_audit_failure BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'PRIVATE_DIAGNOSTIC'); END");
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $response=$this->getJson('/api/admin/agents/pending')->assertForbidden();
        $this->assertStringNotContainsString('PRIVATE_DIAGNOSTIC',$response->getContent());
        Log::shouldHaveReceived('error')->once()->with('security.audit_write_failed',['event'=>'authorization.denied']);
    }
    public function test_audit_review_is_bounded_read_only_and_refuses_false_complete_or_tampered_age(): void
    {
        $admin=$this->auditAdmin();$audit=app(SecurityAudit::class);
        $this->travel(-100)->days();$audit->record('auth.login',$admin);$this->travelBack();
        $audit->record('auth.logout',$admin);$audit->record('auth.login',$admin);
        $first=$audit->review($admin,1);$this->assertSame('PARTIAL',$first['status']);$this->assertSame(1,$first['scanned_rows']);
        $this->assertNotNull($first['next_after_id']);$this->assertSame(1,$first['expired_valid_rows']);
        AuditLog::latest('id')->firstOrFail()->forceFill(['created_at'=>now()->subYears(2)])->save();
        $result=$audit->review($admin);$this->assertSame('FAIL',$result['status']);$this->assertSame(1,$result['invalid_rows']);
        $this->assertSame(1,$result['expired_valid_rows']);$this->assertTrue($result['dry_run']);$this->assertSame(3,AuditLog::count());
        $this->assertArrayNotHasKey('rows',$result);$this->assertArrayNotHasKey('new_values',$result);
    }
    public function test_audit_review_rejects_oversized_legacy_payload_and_owner_access(): void
    {
        $admin=$this->auditAdmin();$audit=app(SecurityAudit::class);
        AuditLog::create(['user_id'=>$admin->id,'action'=>'auth.login','new_values'=>['secret'=>str_repeat('PRIVATE_MARKER',1000)]]);
        $result=$audit->review($admin);$this->assertSame('FAIL',$result['status']);$this->assertSame(1,$result['invalid_rows']);
        $this->assertStringNotContainsString('PRIVATE_MARKER',json_encode($result));
        try{$audit->review($this->account('owner'));$this->fail('Owner must not gain technical audit access.');}
        catch(\Illuminate\Auth\Access\AuthorizationException){$this->assertTrue(true);}
    }
    public function test_audit_review_with_rollout_off_does_not_query_any_database(): void
    {
        $admin=$this->auditAdmin();config(['security.qa_rollout'=>false]);$queries=0;
        \Illuminate\Support\Facades\DB::listen(function()use(&$queries):void{$queries++;});
        try{app(SecurityAudit::class)->review($admin);$this->fail('Disabled rollout must block review.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(404,$e->getStatusCode());}
        $this->assertSame(0,$queries);$this->assertFalse(config('security.mfa_enabled'));
    }
    public function test_agent_verification_and_branch_changes_audit_only_identifiers(): void
    {
        $user=$this->account('agent');$profile=\App\Models\AgentProfile::create(['user_id'=>$user->id,'verification_status'=>'pending']);
        $profile->update(['verification_status'=>'verified','verification_note'=>'PRIVATE_NOTE']);
        $agency=\App\Models\Agency::create(['name'=>'Synthetic agency','slug'=>'synthetic-change']);
        $branch=\App\Models\Branch::create(['agency_id'=>$agency->id,'name'=>'Synthetic branch','status'=>'active']);
        $branch->update(['status'=>'inactive','address'=>'PRIVATE_ADDRESS']);
        $this->assertSame($user->id,AuditLog::where('action','agent.verification_changed')->firstOrFail()->new_values['subject_user_id']);
        $this->assertSame($branch->id,AuditLog::where('action','branch.access_changed')->firstOrFail()->new_values['branch_id']);
        $this->assertStringNotContainsString('PRIVATE_',AuditLog::all()->toJson());
    }
    public function test_repeated_logout_all_rotates_remember_token_and_keeps_old_stamp_invalid(): void
    {
        $user=$this->account('customer');$user->forceFill(['remember_token'=>'synthetic-old-remember'])->save();
        $security=app(SessionSecurity::class);$request=Request::create('/');$request->setLaravelSession(app('session')->driver('array'));
        $security->stamp($request,$user);$security->revokeAll($user);
        $first=$security->state($user)->session_version;$security->revokeAll($user);
        $this->assertFalse($security->valid($request,$user));$this->assertNotSame($first,$security->state($user)->session_version);
        $this->assertNotSame('synthetic-old-remember',$user->fresh()->remember_token);
        $security->stamp($request,$user->fresh());$this->assertTrue($security->valid($request,$user->fresh()));
    }
    public function test_public_otp_cooldown_does_not_reveal_registered_phone(): void
    {
        $known='+628000000091';$unknown='+628000000092';$this->account('customer')->update(['phone'=>$known]);$fake=$this->delivery();
        $first=$this->postJson('/api/auth/phone/otp',['phone'=>$known,'purpose'=>'login'])->assertOk();
        $codeHash=hash('sha256',$fake->code);
        $second=$this->postJson('/api/auth/phone/otp',['phone'=>$unknown,'purpose'=>'login'])->assertOk();
        $this->assertSame($first->getContent(),$second->getContent());$this->assertTrue(hash_equals($codeHash,hash('sha256',$fake->code)));
        $this->assertNull(Cache::get('promerty:otp:'.hash('sha256','login|'.$unknown)));
        $a=$this->postJson('/api/auth/phone/otp',['phone'=>$known,'purpose'=>'login'])->assertUnprocessable();
        $b=$this->postJson('/api/auth/phone/otp',['phone'=>$unknown,'purpose'=>'login'])->assertUnprocessable();
        $this->assertSame($a->getContent(),$b->getContent());
    }
    public function test_public_otp_provider_failure_has_same_generic_response_without_secret_logging(): void
    {
        $known='+628000000093';$unknown='+628000000094';$this->account('customer')->update(['phone'=>$known]);
        $fake=$this->delivery();$fake->fail=true;Log::spy();
        $a=$this->postJson('/api/auth/phone/otp',['phone'=>$known,'purpose'=>'login'])->assertOk();
        $b=$this->postJson('/api/auth/phone/otp',['phone'=>$unknown,'purpose'=>'login'])->assertOk();
        $this->assertSame($a->getContent(),$b->getContent());$this->assertStringNotContainsString('diagnostic',$a->getContent());
        $this->assertNull(Cache::get('promerty:otp:'.hash('sha256','login|'.$known)));
        Log::shouldHaveReceived('warning')->once()->with('security.otp_delivery_failed',['channel'=>'public']);
    }
}
