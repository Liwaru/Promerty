<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\VerifyAccountEmail;
use App\Support\PrivateMailDelivery;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

final class AccountEmailVerificationController extends Controller
{
    public function send(Request $request): RedirectResponse
    {
        abort_if(config('security.email_otp'), 404);
        $user = $request->user();
        $profile = redirect()->route('account.profile');
        if (!$user->email || $user->email_verified_at) {
            return $profile;
        }
        if (!PrivateMailDelivery::available()) {
            return $profile->withErrors(['verification' => 'Pengiriman email verifikasi belum tersedia.']);
        }

        try {
            $path = URL::temporarySignedRoute('account.email.confirm', now()->addHour(), [
                'account' => $user->id,
                'fingerprint' => $this->fingerprint($user),
            ], absolute: false);
            $user->notify(new VerifyAccountEmail(rtrim(config('app.url'), '/').$path));
        } catch (\Throwable) {
            Log::warning('security.email_verification_delivery_failed');

            return $profile->withErrors(['verification' => 'Email verifikasi belum dapat dikirim. Coba lagi nanti.']);
        }

        return $profile->with('email_verification_status', 'Tautan verifikasi dikirim ke alamat email akun Anda.');
    }

    public function show(Request $request, int $account, string $fingerprint): View
    {
        abort_if(config('security.email_otp'), 404);
        $this->authorizeAddress($request->user(), $account, $fingerprint);

        return view('auth.email-confirmation', [
            'confirmationUrl' => route('account.email.complete', [
                'account' => $account,
                'fingerprint' => $fingerprint,
                'expires' => $request->query('expires'),
                'signature' => $request->query('signature'),
            ], false),
        ]);
    }

    public function confirm(Request $request, int $account, string $fingerprint): RedirectResponse
    {
        abort_if(config('security.email_otp'), 404);
        abort_unless((int) $request->user()->id === $account, 403);

        $verified = DB::transaction(function () use ($account, $fingerprint): ?User {
            $user = User::query()->lockForUpdate()->findOrFail($account);
            $this->authorizeAddress($user, $account, $fingerprint);
            if ($user->email_verified_at) {
                return null;
            }
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        });

        if ($verified) {
            event(new Verified($verified));
        }

        return redirect()->route('account.profile')
            ->with('email_verification_status', 'Email Anda sudah terverifikasi.');
    }

    private function authorizeAddress(User $user, int $account, string $fingerprint): void
    {
        abort_unless((int) $user->id === $account && $user->isActive() && $user->email
            && hash_equals($this->fingerprint($user), $fingerprint), 403);
    }

    private function fingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->email, config('app.key'));
    }
}
