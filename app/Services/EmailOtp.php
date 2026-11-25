<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\EmailOtpCode;
use App\Support\PrivateMailDelivery;
use Illuminate\Support\Facades\{DB, Log};

final class EmailOtp
{
    public const LOGIN = 'login';
    public const PASSWORD = 'password';

    private function query(int $user, string $purpose)
    {
        return DB::table('email_otp_challenges')->where(['user_id' => $user, 'purpose' => $purpose]);
    }

    public function marker(User $user, string $nonce): string
    {
        return $this->mac(json_encode([$user->id, $user->email, $user->getAuthPassword(), $nonce], JSON_THROW_ON_ERROR));
    }

    private function mac(#[\SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    public function send(User $user, string $purpose, string $binding): array
    {
        if (!PrivateMailDelivery::available()) {
            return ['error' => 'Pengiriman kode belum tersedia.', 'status' => 503];
        }
        $now = now()->timestamp;
        $result = DB::transaction(function () use ($user, $purpose, $binding, $now) {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($current->isActive() && $current->email === $user->email
                && $current->getAuthPassword() === $user->getAuthPassword()
                && (bool) $current->email_verified_at === ($purpose === self::PASSWORD), 403);
            $prior = $this->query($user->id, $purpose)->first();
            $window = $prior && $now < $prior->window_start + 3600 ? $prior->window_start : $now;
            $sends = $prior && $window === $prior->window_start ? $prior->sends : 0;
            $retry = max($prior ? $prior->sent + 60 - $now : 0, $sends >= 5 ? $window + 3600 - $now : 0);
            if ($retry > 0) {
                return ['error' => 'Tunggu sebelum meminta kode baru.', 'status' => 429, 'retry' => $retry];
            }
            do {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            } while ($prior?->digest && hash_equals($prior->digest, $this->mac("$purpose:{$prior->binding}:$code")));
            $digest = $this->mac("$purpose:$binding:$code");
            DB::table('email_otp_challenges')->updateOrInsert(['user_id' => $user->id, 'purpose' => $purpose], [
                'binding' => $binding, 'digest' => $digest, 'expires' => $now + 600, 'sent' => $now,
                'window_start' => $window, 'sends' => $sends + 1, 'attempts' => 0, 'approved' => false,
            ]);

            return ['code' => $code, 'digest' => $digest];
        });
        if (isset($result['error'])) {
            return $result;
        }
        try {
            $user->notify(new EmailOtpCode($result['code']));
        } catch (\Throwable) {
            $this->query($user->id, $purpose)->where('digest', $result['digest'])->update(['digest' => null]);
            Log::warning('security.email_otp_delivery_failed');

            return ['error' => 'Kode belum dapat dikirim. Silakan coba lagi.', 'status' => 503, 'retry' => 60];
        }

        return ['retry' => 60];
    }

    public function verify(int $id, string $purpose, string $nonce, #[\SensitiveParameter] string $code, string $expected): ?string
    {
        return DB::transaction(function () use ($id, $purpose, $nonce, $code, $expected) {
            $user = User::query()->lockForUpdate()->findOrFail($id);
            abort_unless($user->isActive() && $user->email && hash_equals($expected, $this->marker($user, $nonce))
                && (bool) $user->email_verified_at === ($purpose === self::PASSWORD), 403);
            $query = $this->query($id, $purpose);
            $state = $query->first();
            if (!$state || !hash_equals($state->binding, $expected) || $state->expires <= now()->timestamp) {
                return 'Kode sudah kedaluwarsa. Silakan minta kode baru.';
            }
            if ($state->attempts >= 5) {
                return 'Batas percobaan kode tercapai. Silakan minta kode baru.';
            }
            if (!$state->digest) {
                return 'Kode tidak tersedia. Silakan minta kode baru.';
            }
            if (!hash_equals($state->digest, $this->mac("$purpose:$expected:$code"))) {
                $query->increment('attempts');

                return 'Kode verifikasi salah. Silakan coba lagi.';
            }
            $query->update(['digest' => null, 'approved' => $purpose === self::PASSWORD]);
            if ($purpose === self::LOGIN) {
                $user->forceFill(['email_verified_at' => now()])->saveOrFail();
            }

            return null;
        });
    }

    public function consumePasswordApproval(User $user, string $nonce, string $binding): bool
    {
        return hash_equals($binding, $this->marker($user, $nonce))
            && $this->query($user->id, self::PASSWORD)->where('binding', $binding)->where('approved', true)
                ->where('expires', '>', now()->timestamp)->update(['approved' => false]) === 1;
    }
}
