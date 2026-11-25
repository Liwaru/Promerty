<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordUpdated;
use App\Services\{PasswordLoginAttempts, SecurityAudit, SessionSecurity};
use App\Support\NewPassword;
use App\Support\PrivateMailDelivery;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerPasswordResetController extends Controller
{
    public function requestLink(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        $data['email'] = strtolower(trim($data['email']));
        app(\App\Services\SecurityAudit::class)->publicRequest('auth.recovery_requested','web');

        // Broker cooldown applies only to existing accounts. Keep its outcome
        // private; the request limiter returns 429 independently of existence.
        // Never send reset URLs/tokens to the general log mailer. Tests use a
        // private in-memory transport; real delivery needs a configured provider.
        if (PrivateMailDelivery::available()) $this->sendLink($this->requirePrivilegedVerification($data));

        return response()->json([
            'message' => 'Jika alamat email terdaftar, tautan reset akan dikirim.',
        ], 202);
    }

    public function requestManagedLink(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        Gate::forUser($actor)->authorize('initiatePasswordReset', $user);
        $back = redirect()->route('admin.dashboard', ['section'=>'users']);
        if (!$request->boolean('confirm_reset')) {
            return $back->withErrors(['reset'=>'Konfirmasi permintaan reset password.']);
        }
        if (!$user->isActive() || !$user->email || !$user->email_verified_at) {
            return $back->withErrors(['reset'=>'Akun harus aktif dan emailnya terverifikasi.']);
        }
        if (!PrivateMailDelivery::available()) {
            return $back->withErrors(['reset'=>'Pengiriman tautan reset belum tersedia.']);
        }
        $credentials = $request->validate(['current_password'=>['required','string','max:1024']]);
        $verified = $actor->email && $actor->getAuthPassword() && app(PasswordLoginAttempts::class)->check(
            $actor->email, fn () => Hash::check($credentials['current_password'], $actor->getAuthPassword()));
        if (!$verified) return $back->withErrors(['reset'=>'Autentikasi ulang Admin gagal.']);

        $result = $this->sendLink([
            'id'=>$user->id,
            'email'=>$user->email,
            'role_id'=>$user->role_id,
            'status'=>'active',
            fn ($query) => $query->whereNotNull('email_verified_at'),
        ], function (User $subject, #[\SensitiveParameter] string $token) use ($actor): void {
            app(SecurityAudit::class)->recordManagedReset($actor, $subject);
            $subject->sendPasswordResetNotification($token);
        });
        if ($result !== Password::RESET_LINK_SENT) {
            return $back->withErrors(['reset'=>'Permintaan reset belum berhasil. Coba lagi nanti.']);
        }

        return $back->with('workspace_status', 'Tautan reset dikirim ke email terverifikasi.');
    }

    private function sendLink(array $credentials, ?\Closure $deliver = null): ?string
    {
        try {
            return Password::sendResetLink($credentials, $deliver);
        } catch (\Throwable) {
            Log::warning('security.recovery_delivery_failed');
            return null;
        }
    }

    private function requirePrivilegedVerification(array $credentials): array
    {
        $credentials[] = fn ($query) => $query->where(fn ($account) => $account
            ->whereNotNull('email_verified_at')
            ->orWhereHas('role', fn ($role) => $role->whereNotIn('name', ['admin','owner','super_admin'])));
        return $credentials;
    }

    public function show(Request $request, string $token)
    {
        return view('auth.password-reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => NewPassword::rules(),
        ]);
        $credentials['email'] = strtolower(trim($credentials['email']));

        $status = DB::transaction(function () use ($credentials) {
            // Serialize token redemption and keep the password change and token consumption atomic.
            User::query()->where('email', $credentials['email'])->lockForUpdate()->first(['id']);

            return Password::reset($this->requirePrivilegedVerification($credentials), function (User $user, #[\SensitiveParameter] string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
                app(SecurityAudit::class)->record('auth.password_reset',$user);
            });
        });

        if ($status === Password::PASSWORD_RESET) {
            if (config('security.email_otp') && PrivateMailDelivery::available()) {
                try {
                    User::where('email', $credentials['email'])->first()?->notify(new PasswordUpdated);
                } catch (\Throwable) {
                    Log::warning('security.password_change_notice_failed');
                }
            }
            if ($request->user('web')?->email === $credentials['email']) {
                app(SessionSecurity::class)->invalidateCurrent($request);
            }
            return redirect()->route('password.reset.complete');
        }

        return back()->withInput($request->only('email'))->withErrors([
            'email' => 'Tautan reset tidak valid atau sudah kedaluwarsa. Minta tautan baru untuk melanjutkan.',
        ]);
    }
}
