<?php

namespace Tests\Feature;

use App\Models\{Role, User};
use App\Notifications\{EmailOtpCode, PasswordUpdated};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Hash, Notification, Schema};
use Tests\Support\FeedDatabase;
use Tests\TestCase;

final class EmailOtpTest extends TestCase
{
    use FeedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFeedDatabase();
        (require database_path('migrations/2026_11_25_120000_create_email_otp_challenges_table.php'))->up();
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        config(['security.email_otp' => true, 'mail.default' => 'array']);
        Notification::fake();
    }

    public function test_rollout_off_preserves_password_login_and_verified_account_skips_otp(): void
    {
        $user = $this->account(true);
        $this->login($user)->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->postJson('/web-auth/logout')->assertOk();
        config(['security.email_otp' => false]);
        $user->forceFill(['email_verified_at' => null])->save();
        $this->login($user)->assertOk();
        Notification::assertNothingSent();
    }

    public function test_unverified_password_login_is_guest_until_code_is_consumed(): void
    {
        $user = $this->account();
        $this->login($user)->assertAccepted()->assertJsonPath('verification_url', '/account/email/otp/login');
        $this->assertGuest('web');
        $this->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'SyntheticStart01'])->assertForbidden();
        $this->get('/account/email/otp/login')->assertOk()->assertSee('Digit 6');
        $code = $this->issuedCode($user);
        $this->verify('login', ' '.$code)->assertUnprocessable();
        $this->assertNotSame($code, DB::table('email_otp_challenges')->value('digest'));
        $this->verify('login', $code)->assertOk()->assertJsonPath('redirect_to', '/');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertNull(DB::table('email_otp_challenges')->value('digest'));
        $this->verify('login', $code)->assertForbidden();
    }

    public function test_five_wrong_codes_block_correct_code_until_resend_and_old_code_is_expired(): void
    {
        $user = $this->account();
        $this->login($user)->assertAccepted();
        $code = $this->issuedCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/account/email/otp/login/resend')->assertTooManyRequests();
        for ($i = 0; $i < 5; $i++) {
            $this->verify('login', $wrong)->assertUnprocessable()
                ->assertJsonPath('errors.email_otp.0', 'Kode verifikasi salah. Silakan coba lagi.');
        }
        $this->verify('login', $code)->assertUnprocessable()
            ->assertJsonPath('errors.email_otp.0', 'Batas percobaan kode tercapai. Silakan minta kode baru.');
        $this->travel(61)->seconds();
        $this->postJson('/account/email/otp/login/resend')->assertOk();
        $next = $this->issuedCode($user);
        $this->assertNotSame($code, $next);
        $this->verify('login', $code)->assertUnprocessable();
        $this->travel(10)->minutes();
        $this->verify('login', $next)->assertUnprocessable()
            ->assertJsonPath('errors.email_otp.0', 'Kode sudah kedaluwarsa. Silakan minta kode baru.');
        $this->assertGuest('web');
    }

    public function test_hourly_send_cap_is_shared_and_wrong_purpose_or_missing_session_is_denied(): void
    {
        $user = $this->account();
        $this->verify('login', '123456')->assertForbidden();
        $this->login($user)->assertAccepted();
        $this->verify('password', $this->issuedCode($user))->assertForbidden();
        for ($i = 0; $i < 4; $i++) {
            $this->travel(61)->seconds();
            $this->postJson('/account/email/otp/login/resend')->assertOk();
        }
        $this->travel(61)->seconds();
        $this->postJson('/account/email/otp/login/resend')->assertTooManyRequests();
        Notification::assertSentToTimes($user, EmailOtpCode::class, 5);
    }

    public function test_password_change_requires_reauthentication_and_single_use_otp_grant(): void
    {
        $user = $this->account(true);
        $this->login($user)->assertOk();
        $old = $user->refresh()->getAuthPassword();
        $user->createToken('synthetic-existing-token');
        $data = ['password' => 'SyntheticNext01', 'password_confirmation' => 'SyntheticNext01'];
        $this->patchJson('/settings/password', $data)->assertUnprocessable();
        $this->patchJson('/api/me/password', $data)->assertForbidden();
        $this->post('/settings/password/otp', ['current_password' => 'synthetic-wrong'])
            ->assertSessionHasErrors('current_password');
        $this->post('/settings/password/otp', ['current_password' => 'SyntheticStart01'])
            ->assertRedirect('/account/email/otp/password');
        $code = $this->issuedCode($user);
        $this->verify('password', $code)->assertOk();
        $this->assertSame($old, $user->refresh()->getAuthPassword());
        $this->verify('password', $code)->assertUnprocessable();
        $this->patchJson('/settings/password', ['password' => 'ShortPass', 'password_confirmation' => 'ShortPass'])
            ->assertUnprocessable();
        $this->assertSame($old, $user->refresh()->getAuthPassword());
        $this->patch('/settings/password', $data)->assertRedirect('/settings');
        $this->assertTrue(Hash::check($data['password'], $user->refresh()->getAuthPassword()));
        $this->assertFalse($user->tokens()->exists());
        $this->getJson('/web-auth/me')->assertJsonPath('user.id', $user->id);
        $this->patchJson('/settings/password', $data)->assertUnprocessable();
        Notification::assertSentToTimes($user, PasswordUpdated::class, 1);
    }

    public function test_mail_log_never_receives_otp_and_does_not_authenticate(): void
    {
        $user = $this->account();
        config(['mail.default' => 'log']);
        $this->login($user)->assertAccepted();
        $this->get('/account/email/otp/login')->assertOk()->assertSee('Pengiriman kode belum tersedia.');
        $this->assertGuest();
        $this->assertFalse(DB::table('email_otp_challenges')->exists());
        Notification::assertNothingSent();
    }

    public function test_browser_form_can_confirm_without_javascript_and_preserves_an_allowed_destination(): void
    {
        $user = $this->account();
        $this->postJson('/web-auth/login', ['email' => $user->email, 'password' => 'SyntheticStart01',
            'return_to' => '/posts/1'])->assertAccepted();
        $this->post('/account/email/otp/login', ['digits' => str_split($this->issuedCode($user))])
            ->assertRedirect('/posts/1');
        $this->assertAuthenticatedAs($user);
    }

    public function test_unverified_api_registration_issues_no_token(): void
    {
        $this->account();
        $this->postJson('/api/auth/register', ['name' => 'Synthetic API User', 'email' => 'api@example.test',
            'password' => 'SyntheticStart01', 'password_confirmation' => 'SyntheticStart01'])
            ->assertAccepted()->assertJsonMissingPath('token');
        $this->assertGuest();
        $this->assertFalse(User::where('email', 'api@example.test')->firstOrFail()->tokens()->exists());
    }

    private function account(bool $verified = false): User
    {
        $role = Role::firstOrCreate(['name' => 'customer'], ['level' => 1]);

        return User::factory()->create(['role_id' => $role->id, 'email' => 'otp@example.test', 'status' => 'active',
            'email_verified_at' => $verified ? now() : null, 'password' => 'SyntheticStart01']);
    }

    private function login(User $user)
    {
        return $this->postJson('/web-auth/login', ['email' => $user->email, 'password' => 'SyntheticStart01']);
    }

    private function verify(string $purpose, #[\SensitiveParameter] string $code)
    {
        return $this->postJson('/account/email/otp/'.$purpose, ['email_otp' => $code]);
    }

    private function issuedCode(User $user): string
    {
        $mail = Notification::sent($user, EmailOtpCode::class)->last()?->toMail($user);
        $this->assertTrue((bool) preg_match('/[0-9]{6}/', $mail?->introLines[0] ?? '', $matches));

        return $matches[0];
    }
}
