<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FeedDatabase;
use Tests\TestCase;

class CustomerAuthenticationTest extends TestCase
{
    public function test_framework_defaults_do_not_restore_unapproved_service_options(): void
    {
        foreach ([
            'mail.mailers.ses', 'mail.mailers.postmark', 'mail.mailers.resend',
            'mail.mailers.failover', 'mail.mailers.roundrobin', 'services.slack',
            'filesystems.disks.s3', 'queue.connections.sqs', 'cache.stores.dynamodb',
            'logging.channels.slack', 'logging.channels.papertrail',
            'broadcasting.connections.pusher', 'broadcasting.connections.ably',
        ] as $path) {
            $this->assertNull(config($path), $path);
        }

        foreach ([
            'filesystems.disks.local.driver'=>'local', 'filesystems.disks.public.driver'=>'local',
            'queue.connections.database.driver'=>'database', 'mail.mailers.smtp.transport'=>'smtp',
            'mail.mailers.array.transport'=>'array',
        ] as $path => $driver) {
            $this->assertSame($driver, config($path));
        }
        $this->assertIsArray(config('services.google'));
    }

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

    public function test_feed_login_modal_matches_the_approved_customer_design_and_keeps_google_unavailable_honest(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('id="feed-auth"', false)
            ->assertSee('Masuk Ke Promerty')
            ->assertSee('nama@email.com')
            ->assertSee('Lupa password?')
            ->assertSee('atau lanjutkan dengan')
            ->assertSee('Lanjutkan dengan Google')
            ->assertSee('disabled aria-describedby="auth-google-note"', false)
            ->assertSee('Login Google belum dikonfigurasi. Gunakan email dan password.')
            ->assertSee('Belum punya akun?')
            ->assertSee('Daftar sekarang')
            ->assertDontSee('Masuk untuk menyimpan pilihan dan terhubung dengan Agent.');
    }

    public function test_customer_can_login_with_email_and_password(): void
    {
        $user = $this->customer('member@example.test', 'correct horse battery');

        $this->postJson('/web-auth/login', [
            'email' => 'MEMBER@example.test',
            'password' => 'correct horse battery',
        ])->assertOk()->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'customer');

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_invalid_customer_login_returns_validation_error(): void
    {
        $this->customer('member@example.test', 'correct horse battery');

        $this->postJson('/web-auth/login', [
            'email' => 'member@example.test',
            'password' => 'incorrect',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_password_reset_is_generic_and_can_reset_password_with_a_valid_token(): void
    {
        Notification::fake();
        $user = $this->customer('member@example.test');

        $response = $this->postJson('/web-auth/password/forgot', ['email' => strtoupper($user->email)])
            ->assertAccepted()
            ->assertJsonPath('message', 'Jika alamat email terdaftar, tautan reset akan dikirim.');

        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$token) {
            $token = $notification->token;
            return true;
        });
        $this->assertNotEmpty($token);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()->assertSee('Atur ulang kata sandi');

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new secure password',
            'password_confirmation' => 'new secure password',
        ])->assertRedirect(route('password.reset.complete'));

        $this->assertTrue(Hash::check('new secure password', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->get(route('password.reset.complete'))->assertOk()->assertSee('Password berhasil diperbarui');
    }

    public function test_password_reset_request_does_not_disclose_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/web-auth/password/forgot', ['email' => 'unknown@example.test'])
            ->assertAccepted()
            ->assertJsonPath('message', 'Jika alamat email terdaftar, tautan reset akan dikirim.');

        Notification::assertNothingSent();
    }

    public function test_privileged_self_service_reset_requires_verified_email_and_uses_configured_origin(): void
    {
        Notification::fake();
        config(['app.url'=>'http://127.0.0.1:8046']);
        $user = $this->customer('staff@example.test');
        $user->update(['role_id'=>Role::firstOrCreate(['name'=>'owner'], ['level'=>5])->id]);

        $this->postJson('/web-auth/password/forgot', ['email'=>$user->email])->assertAccepted();
        Notification::assertNothingSent();
        $oldToken = \Illuminate\Support\Facades\Password::createToken($user);
        $this->post('/password/reset', [
            'email'=>$user->email, 'token'=>$oldToken,
            'password'=>'synthetic-new-password', 'password_confirmation'=>'synthetic-new-password',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('initial password', $user->fresh()->password));
        \Illuminate\Support\Facades\Password::deleteToken($user);

        $user->forceFill(['email_verified_at'=>now()])->save();
        $this->withHeader('Host','untrusted.example.test')->postJson('/web-auth/password/forgot', ['email'=>$user->email])->assertAccepted();
        Notification::assertSentTo($user, ResetPasswordNotification::class, fn ($notification) =>
            str_starts_with($notification->toMail($user)->actionUrl, 'http://127.0.0.1:8046/password/reset/'));
    }

    private function customer(string $email, string $password = 'initial password'): User
    {
        $role = Role::firstOrCreate(['name' => 'customer'], ['level' => 1]);

        return User::create([
            'name' => 'Customer QA',
            'email' => $email,
            'password' => $password,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }
}
