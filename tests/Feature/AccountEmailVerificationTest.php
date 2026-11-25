<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Notifications\VerifyAccountEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FeedDatabase;
use Tests\TestCase;

class AccountEmailVerificationTest extends TestCase
{
    use FeedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFeedDatabase();
        config(['app.url' => 'http://localhost', 'mail.default' => 'array']);
    }

    public function test_only_current_account_can_confirm_and_get_does_not_verify(): void
    {
        Notification::fake();
        Event::fake([Verified::class]);
        $user = $this->account('member@example.test');
        $other = $this->account('other@example.test');
        $url = $this->sendLink($user);

        $this->actingAs($other)->get($url)->assertForbidden();
        $this->post($url)->assertForbidden();
        $this->actingAs($user)->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('Konfirmasi email');
        $this->assertNull($user->refresh()->email_verified_at);
        $this->post($url)->assertRedirect(route('account.profile'));
        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertNull($other->refresh()->email_verified_at);
        $this->post($url)->assertRedirect(route('account.profile'));
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_tampered_expired_and_stale_links_leave_the_address_unverified(): void
    {
        Notification::fake();
        $user = $this->account('member@example.test');
        $url = $this->sendLink($user);

        $this->post($url.'&extra=1')->assertForbidden();
        $this->post(strtok($url, '?'))->assertForbidden();
        $user->update(['email' => 'changed@example.test']);
        $this->post($url)->assertForbidden();
        $this->assertNull($user->refresh()->email_verified_at);

        $url = $this->sendLink($user);
        $this->travel(61)->minutes();
        $this->post($url)->assertForbidden();
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_guest_and_log_mailer_cannot_send_and_requests_are_limited(): void
    {
        Notification::fake();
        $url = route('account.email.send');
        $this->post($url)->assertRedirect();
        $user = $this->account('member@example.test');
        config(['mail.default' => 'log']);
        $this->actingAs($user)->post($url)->assertSessionHasErrors('verification');
        Notification::assertNothingSent();

        config(['mail.default' => 'array']);
        $this->post($url)->assertSessionHas('email_verification_status');
        $this->post($url)->assertSessionHas('email_verification_status');
        $this->post($url)->assertTooManyRequests();
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_transport_failure_logs_only_the_generic_event(): void
    {
        $user = $this->account('member@example.test');
        $this->mock(ChannelManager::class)->shouldReceive('send')->once()
            ->andThrow(new \RuntimeException('synthetic private transport error'));
        Log::shouldReceive('warning')->once()->with('security.email_verification_delivery_failed');
        $this->actingAs($user)->post(route('account.email.send'))
            ->assertSessionHasErrors('verification')
            ->assertSessionMissing('email_verification_status');
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_verification_message_reaches_the_in_memory_transport_for_the_current_address(): void
    {
        $user = $this->account('member@example.test');
        $this->actingAs($user)->post(route('account.email.send'), ['email' => 'other@example.test'])
            ->assertSessionHas('email_verification_status');

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages->sole();
        $this->assertSame([$user->email], array_map(
            fn ($address) => $address->getAddress(), $message->getEnvelope()->getRecipients()
        ));
        $this->assertStringContainsString('http://localhost/account/email/confirm/', $message->getOriginalMessage()->getTextBody());
    }

    private function sendLink(User $user): string
    {
        $this->actingAs($user)->post(route('account.email.send'))
            ->assertRedirect(route('account.profile'))
            ->assertSessionHas('email_verification_status');
        $notification = Notification::sent($user, VerifyAccountEmail::class)->last();
        $this->assertInstanceOf(VerifyAccountEmail::class, $notification);
        $url = $notification->toMail($user)->actionUrl;
        $this->assertStringStartsWith('http://localhost/account/email/confirm/', $url);
        $this->assertStringNotContainsString($user->email, $url);

        return $url;
    }

    private function account(string $email): User
    {
        $role = Role::firstOrCreate(['name' => 'customer'], ['level' => 1]);

        return User::factory()->unverified()->create([
            'role_id' => $role->id,
            'email' => $email,
            'status' => 'active',
        ]);
    }
}
