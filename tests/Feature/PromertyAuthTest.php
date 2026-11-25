<?php

namespace Tests\Feature;

use App\Services\OtpService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PromertyAuthTest extends TestCase
{
    private function privateDelivery(): \App\Contracts\OtpDelivery
    {
        $delivery=new class implements \App\Contracts\OtpDelivery {
            public string $code='';
            public function available():bool{return true;}
            public function send(string $phone,string $purpose,string $code):void{$this->code=$code;}
        };
        $this->app->instance(\App\Contracts\OtpDelivery::class,$delivery);return $delivery;
    }
    public function test_protected_routes_require_authentication(): void
    {
        foreach (['/api/user', '/api/me', '/api/admin/agents/pending'] as $uri) {
            $this->getJson($uri)->assertUnauthorized();
        }
        foreach (['/api/auth/logout', '/api/auth/logout-all', '/api/me/phone/otp', '/api/me/phone/verify', '/api/admin/agents'] as $uri) {
            $this->postJson($uri)->assertUnauthorized();
        }
        $this->patchJson('/api/me')->assertUnauthorized();
    }

    public function test_google_requires_configuration(): void
    {
        config(['promerty.google_client_id' => null]);
        $this->postJson('/api/auth/google', ['id_token' => 'invalid'])
            ->assertStatus(500)
            ->assertJsonPath('message', 'GOOGLE_CLIENT_ID belum dikonfigurasi.');
    }

    public function test_local_otp_is_single_use_and_requests_are_throttled(): void
    {
        $delivery=$this->privateDelivery();
        Log::shouldReceive('info')->never();
        $service = app(OtpService::class);
        $service->issue('+628123456789', 'login');
        $code=$delivery->code;
        try {
            $service->issue('+628123456789', 'login');
            $this->fail('Repeated requests must be throttled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('phone', $exception->errors());
        }
        $service->verify('+628123456789', 'login', $code);
        $this->expectException(ValidationException::class);
        $service->verify('+628123456789', 'login', $code);
    }

    public function test_failed_attempt_does_not_extend_otp_expiry(): void
    {
        $delivery=$this->privateDelivery();
        Log::shouldReceive('info')->never();
        $service = app(OtpService::class);
        $service->issue('+628123456789', 'register');
        $code=$delivery->code;
        $this->travel(4)->minutes();
        try {
            $service->verify('+628123456789', 'register', '000000');
            $this->fail('Invalid OTP must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('otp', $exception->errors());
        }
        $this->travel(2)->minutes();
        $this->expectException(ValidationException::class);
        $service->verify('+628123456789', 'register', $code);
    }

    public function test_production_does_not_log_or_issue_otp_without_sms_provider(): void
    {
        $this->app->instance('env', 'production');
        Log::shouldReceive('info')->never();
        try {
            app(OtpService::class)->issue('+628123456789', 'login');
            $this->fail('Production must require an SMS provider.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
    }
}
