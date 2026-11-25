<?php

namespace App\Support;

use App\Services\PhoneNormalizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class AuthenticationRateLimits
{
    public static function register(): void
    {
        // Password login keeps an independent IP ceiling. Password failures share
        // one account budget across web and API in PasswordLoginAttempts.
        $limits = [
            'auth-login-password' => [30, 0, null],
            'auth-register-web' => [5, 3, 'email'],
            'auth-register-api' => [5, 3, 'email'],
            'auth-recovery' => [10, 5, 'email'],
            'auth-reset' => [10, 5, 'email'],
            'auth-otp-request' => [5, 3, 'phone'],
            'auth-otp-verify' => [10, 5, 'phone'],
            'auth-phone-register' => [5, 3, 'phone'],
            'auth-phone-login' => [10, 5, 'phone'],
            // An unverified Google token is not a trusted account identifier.
            'auth-google' => [10, 0, null],
        ];

        foreach ($limits as $name => [$ipLimit, $identityLimit, $field]) {
            RateLimiter::for($name, static function (Request $request) use ($name, $ipLimit, $identityLimit, $field): array {
                $reply = static fn (Request $request, array $headers) => response()->json([
                    'message' => $name === 'auth-login-password'
                        ? \App\Services\PasswordLoginAttempts::THROTTLED
                        : 'Terlalu banyak percobaan. Silakan coba lagi nanti.',
                ], 429, $headers);
                $result = [Limit::perMinute($ipLimit)->by('ip:'.hash('sha256', (string) $request->ip()))->response($reply)];
                if ($field !== null) {
                    $value = $request->input($field);
                    $identity = is_string($value) ? strtolower(trim(substr($value, 0, 255))) : '';
                    if ($field === 'phone' && $identity !== '') {
                        try {
                            $identity = app(PhoneNormalizer::class)->normalize($identity);
                        } catch (\InvalidArgumentException) {
                            // Invalid values still consume the IP budget.
                            $identity = '';
                        }
                    }
                    if ($identity !== '') {
                        $result[] = Limit::perMinute($identityLimit)->by('identity:'.hash('sha256', $identity))->response($reply);
                    }
                }

                return $result;
            });
        }
        RateLimiter::for('auth-google-link', static fn (Request $request) => [
            Limit::perMinute(10)->by('google-link-ip:'.hash('sha256', (string) $request->ip())),
            Limit::perMinute(5)->by('google-link-user:'.(string) $request->user('web')?->id),
        ]);
    }
}
