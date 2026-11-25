<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\{EmailOtp, PasswordLoginAttempts, SessionSecurity};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Hash};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EmailOtpController extends Controller
{
    public function __construct(private readonly EmailOtp $otp) {}

    public function start(Request $request, User $user, string $purpose): array
    {
        $active = $request->session()->get('email_otp.'.$purpose);
        $nonce = $active && $active['id'] === $user->id ? $active['nonce'] : Str::random(48);
        $pending = ['id' => $user->id, 'nonce' => $nonce,
            'binding' => $this->otp->marker($user, $nonce), 'until' => now()->addMinutes(20)->timestamp];
        $result = $this->otp->send($user, $purpose, $pending['binding']);
        $request->session()->put('email_otp.'.$purpose, $pending);

        return $result;
    }

    public function startPassword(Request $request)
    {
        abort_unless(config('security.email_otp'), 404);
        $user = $request->user();
        if (!$user->email_verified_at) {
            return redirect()->route('account.profile')->withErrors(['verification' => 'Verifikasi email sebelum mengubah password.']);
        }
        $data = $request->validate(['current_password' => ['required', 'string', 'max:1024']]);
        if (!$user->getAuthPassword() || !app(PasswordLoginAttempts::class)->check(
            $user->email, fn () => Hash::check($data['current_password'], $user->getAuthPassword())
        )) {
            throw ValidationException::withMessages(['current_password' => 'Password saat ini tidak dapat diverifikasi.']);
        }
        $result = $this->start($request, $user, EmailOtp::PASSWORD);
        $request->session()->flash('otp_notice', $result['error'] ?? 'Kode dikirim jika pengiriman berhasil.');
        $request->session()->flash('otp_retry', $result['retry'] ?? 0);

        return redirect()->route('email-otp.show', EmailOtp::PASSWORD);
    }

    public function show(Request $request, string $purpose)
    {
        $this->pending($request, $purpose);

        return view('auth.email-otp', ['purpose' => $purpose]);
    }

    public function resend(Request $request, string $purpose)
    {
        [$user, $pending] = $this->pending($request, $purpose);
        $result = $this->otp->send($user, $purpose, $pending['binding']);
        if (!isset($result['error'])) {
            $request->session()->put('email_otp.'.$purpose.'.until', now()->addMinutes(20)->timestamp);
        }
        $message = $result['error'] ?? 'Kode baru dikirim.';
        if (!$request->expectsJson()) {
            return back()->with('otp_notice', $message)->with('otp_retry', $result['retry'] ?? 0);
        }

        return response()->json(['message' => $message, 'retry' => $result['retry'] ?? 0], $result['status'] ?? 200);
    }

    public function verify(Request $request, string $purpose)
    {
        [$user, $pending] = $this->pending($request, $purpose);
        if (!$request->expectsJson()) {
            $digits = $request->validate(['digits' => ['required', 'array', 'list', 'size:6'],
                'digits.*' => ['required', 'string', 'regex:/\A[0-9]\z/']]);
            $request->merge(['email_otp' => implode('', $digits['digits'])]);
        }
        $data = $request->validate(['email_otp' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);
        $error = $this->otp->verify($user->id, $purpose, $pending['nonce'], $data['email_otp'], $pending['binding']);
        if ($error) {
            throw ValidationException::withMessages(['email_otp' => $error]);
        }
        if ($purpose === EmailOtp::PASSWORD) {
            $request->session()->put('email_otp.password_approved', true);
            $destination = route('account.settings', absolute: false).'#settings-account';
        } else {
            $request->session()->forget('email_otp.login');
            $user->refresh();
            abort_unless($user->isActive() && $user->email_verified_at
                && hash_equals($pending['binding'], $this->otp->marker($user, $pending['nonce'])), 403);
            $result = app(FeedSessionController::class)->completeLogin($request, $user);
            $destination = $pending['return_to'] ?? $result->getData(true)['redirect_to'];
        }

        return $request->expectsJson() ? response()->json(['redirect_to' => $destination]) : redirect($destination);
    }

    private function pending(Request $request, string $purpose): array
    {
        abort_unless(config('security.email_otp'), 404);
        $pending = $request->session()->get('email_otp.'.$purpose);
        abort_unless($pending && $pending['until'] > now()->timestamp, 403);
        $user = User::findOrFail($pending['id']);
        abort_unless($user->isActive() && $user->email && hash_equals($pending['binding'], $this->otp->marker($user, $pending['nonce'])), 403);
        abort_unless($purpose === EmailOtp::LOGIN ? Auth::guard('web')->guest() && !$user->email_verified_at
            : $request->user('web')?->is($user) && $user->email_verified_at, 403);

        return [$user, $pending];
    }
}
