<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use App\Contracts\OtpDelivery;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private readonly OtpDelivery $delivery) {}
    public function available(): bool { return $this->delivery->available(); }

    public function issue(string $phone, string $purpose): void
    {
        $this->issueWithEligibility($phone,$purpose,true);
    }

    /** Same public cooldown/outcome for eligible and ineligible accounts. */
    public function issuePublic(string $phone,string $purpose,bool $eligible): void
    {
        abort_unless($this->delivery->available(),503,'Layanan SMS OTP belum dikonfigurasi.');
        try{$this->issueWithEligibility($phone,$purpose,$eligible);}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){
            if($error->getStatusCode()!==503)throw $error;
            // Do not turn a provider outage into an account-existence oracle.
            // No provider exception, phone, code or credential is logged.
            \Illuminate\Support\Facades\Log::warning('security.otp_delivery_failed',['channel'=>'public']);
        }
    }

    private function issueWithEligibility(string $phone,string $purpose,bool $eligible): void
    {
        abort_unless(in_array($purpose, ['login','register','verify'], true), 422);
        abort_unless($this->delivery->available(), 503, 'Layanan SMS OTP belum dikonfigurasi.');
        Cache::lock($this->cacheKey($phone,$purpose).':lock',10)->block(2,function () use ($phone,$purpose,$eligible): void {

        $rateKey = 'otp-request:'.hash('sha256', "{$purpose}|{$phone}");
        $maxPerMinute = max(1,min(10,(int) config('promerty.otp.max_requests_per_minute', 1)));

        if (RateLimiter::tooManyAttempts($rateKey, $maxPerMinute)) {
            throw ValidationException::withMessages([
                'phone' => 'Terlalu banyak permintaan OTP. Coba lagi sebentar.',
            ]);
        }

        RateLimiter::hit($rateKey, 60);
        if(!$eligible)return; // Consume the same cooldown without creating/sending an OTP.

        $digits = (int) config('promerty.otp.digits', 6);
        abort_unless($digits >= 6 && $digits <= 8,503);
        $min = 10 ** ($digits - 1);
        $max = (10 ** $digits) - 1;
        $code = (string) random_int($min, $max);

        $ttlMinutes = max(1,min(10,(int) config('promerty.otp.ttl_minutes', 5)));
        $expiresAt = now()->addMinutes($ttlMinutes);

        Cache::put(
            $this->cacheKey($phone, $purpose),
            [
                'hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => $expiresAt->getTimestamp(),
            ],
            $expiresAt
        );

        try { $this->delivery->send($phone,$purpose,$code); }
        catch (\Throwable) { Cache::forget($this->cacheKey($phone,$purpose)); abort(503,'Layanan SMS OTP belum tersedia.'); }
        });
    }

    public function verify(string $phone, string $purpose, string $code): void
    {
        abort_unless(in_array($purpose,['login','register','verify'],true),422);
        Cache::lock($this->cacheKey($phone,$purpose).':lock',10)->block(2,function () use ($phone,$purpose,$code): void {
        $key = $this->cacheKey($phone, $purpose);
        $payload = Cache::get($key);

        if (!$payload || ($payload['expires_at'] ?? 0) <= now()->getTimestamp()) {
            throw ValidationException::withMessages([
                'otp' => 'OTP sudah kedaluwarsa atau tidak ditemukan.',
            ]);
        }

        $maxAttempts = max(1,min(10,(int) config('promerty.otp.max_verify_attempts', 5)));

        if (($payload['attempts'] ?? 0) >= $maxAttempts) {
            Cache::forget($key);

            throw ValidationException::withMessages([
                'otp' => 'Terlalu banyak percobaan OTP. Minta OTP baru.',
            ]);
        }

        if (!preg_match('/^[0-9]{6,8}$/D',$code) || !Hash::check($code, $payload['hash'])) {
            $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;
            Cache::put($key, $payload, $payload['expires_at'] - now()->getTimestamp());

            throw ValidationException::withMessages([
                'otp' => 'Kode OTP tidak valid.',
            ]);
        }

        Cache::forget($key);
        });
    }

    private function cacheKey(string $phone, string $purpose): string
    {
        return 'promerty:otp:'.hash('sha256', "{$purpose}|{$phone}");
    }
}
