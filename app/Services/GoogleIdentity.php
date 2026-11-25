<?php

namespace App\Services;

use App\Models\{Role, User, UserAuthProvider};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** No provider tokens are accepted or persisted by the identity layer. */
final class GoogleIdentity
{
    public function resolve(array $claims, ?User $linkTo = null): User
    {
        $sub = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (!is_string($sub) || $sub === '' || strlen($sub) > 255 || preg_match('/[^\x21-\x7e]/', $sub)
            || !is_string($email) || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || ($claims['email_verified'] ?? false) !== true) $this->reject();
        $email = strtolower($email);

        return DB::transaction(function () use ($claims, $sub, $email, $linkTo): User {
            $provider = UserAuthProvider::where('provider', 'google')->where('provider_user_id', $sub)->lockForUpdate()->first();
            if ($provider) {
                // Existing database collations may be case-insensitive; identity comparison must be exact.
                if (!hash_equals($provider->provider_user_id, $sub) || ($linkTo && $provider->user_id !== $linkTo->id)) $this->reject();
                $user = User::whereKey($provider->user_id)->lockForUpdate()->first();
                if (!$user || !$user->isActive()) $this->reject();
                if (config('security.email_otp') && !$user->email_verified_at) {
                    // A linked provider can prove the current Gmail address, but
                    // cannot verify a different local mailbox.
                    if (!str_ends_with($email, '@gmail.com') || strtolower((string) $user->email) !== $email) {
                        $this->reject();
                    }
                    $user->forceFill(['email_verified_at' => now()])->saveOrFail();
                }
                return $user; // A changed Google email never changes the Promerty identity or role.
            }
            if ($linkTo) {
                $user = User::whereKey($linkTo->id)->lockForUpdate()->first();
                if (!$user || !$user->isActive() || $user->password !== $linkTo->password
                    || UserAuthProvider::where('user_id', $user->id)->where('provider', 'google')->exists()) $this->reject();
            } else {
                if (User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw ValidationException::withMessages(['google' => 'Masuk dengan metode akun yang sudah ada, lalu hubungkan Google melalui Keamanan Akun di Profil.']);
                }
                $user = new User(['name' => mb_substr(is_string($claims['name'] ?? null) && trim($claims['name']) !== '' ? $claims['name'] : 'Customer Promerty', 0, 150),
                    'email' => $email, 'role_id' => Role::where('name', 'customer')->firstOrFail()->id, 'status' => 'active']);
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            UserAuthProvider::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => $sub]);
            app(SecurityAudit::class)->record($linkTo ? 'auth.google_linked' : 'auth.google_registered', $user, ['channel' => 'google']);
            return $user;
        });
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['google' => 'Identitas Google tidak dapat digunakan. Periksa akun atau gunakan metode masuk lainnya.']);
    }
}
