<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\PasswordUpdated;
use App\Services\{EmailOtp, PasswordLoginAttempts, SecurityAudit, SessionSecurity};
use App\Support\{NewPassword, PrivateMailDelivery};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Log, Password};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountPasswordController extends Controller
{
    public function update(Request $request)
    {
        $actor = $request->user();
        $emailOtp = config('security.email_otp');
        if ($emailOtp && $request->is('api/*')) {
            abort(403, 'Ubah password melalui Pengaturan akun di website.');
        }
        $pending = $emailOtp ? $request->session()->get('email_otp.password') : null;
        $data = $request->validate([
            'current_password' => $emailOtp ? ['prohibited'] : ['required', 'string', 'max:1024'],
            'password' => NewPassword::rules(),
            'user_id' => ['prohibited'],
        ]);

        $previous = $actor->getAuthPassword();
        if (!$emailOtp && (!$previous || !$actor->email || !app(PasswordLoginAttempts::class)->check(
            $actor->email, fn () => Hash::check($data['current_password'], $previous)
        ))) {
            throw ValidationException::withMessages(['current_password' => 'Password saat ini tidak dapat diverifikasi.']);
        }

        DB::transaction(function () use ($actor, $previous, $data, $request, $emailOtp, $pending): void {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (!$user->isActive() || $user->email !== $actor->email || $user->getAuthPassword() !== $previous) {
                throw ValidationException::withMessages(['current_password' => 'Sesi akun berubah. Silakan masuk kembali.']);
            }
            if ($emailOtp && (!$user->email_verified_at || !$pending || $pending['id'] !== $user->id
                || !app(EmailOtp::class)->consumePasswordApproval($user, $pending['nonce'], $pending['binding']))) {
                throw ValidationException::withMessages(['current_password' => 'Verifikasi password dan email diperlukan kembali.']);
            }
            $user->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->saveOrFail();
            $user->tokens()->delete();
            Password::deleteToken($user);
            app(SecurityAudit::class)->record('account.password_changed', $user, ['channel' => $request->is('api/*') ? 'api' : 'web']);
        });

        if ($emailOtp) {
            $actor->refresh();
            $request->session()->forget('email_otp');
            $request->session()->regenerate(true);
            app(SessionSecurity::class)->stamp($request, $actor);
            if (PrivateMailDelivery::available()) {
                try {
                    $actor->notify(new PasswordUpdated);
                } catch (\Throwable) {
                    Log::warning('security.password_change_notice_failed');
                }
            }

            return redirect()->route('account.settings')->with('password_updated', true);
        }

        if ($request->hasSession() && $request->user('web')?->is($actor)) {
            app(SessionSecurity::class)->invalidateCurrent($request);
        }

        return $request->is('api/*') || $request->expectsJson()
            ? response()->json(['message' => 'Password diperbarui. Silakan masuk kembali.'])
            : redirect()->route('password.reset.complete');
    }
}
