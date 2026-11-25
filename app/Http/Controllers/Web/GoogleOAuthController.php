<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\{GoogleIdentity, QaMfa, SecurityAudit, SessionSecurity};
use App\Support\{GoogleOAuth, LoginDestination};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Hash};
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class GoogleOAuthController extends Controller
{
    public function redirect(Request $request)
    {
        if ($request->user('web')) return redirect(LoginDestination::for($request->user('web'))['redirect_to']);
        return $this->begin($request, ['purpose' => 'login', 'return_to' => GoogleOAuth::returnPath($request->query('return_to'))]);
    }

    public function account()
    {
        return redirect($this->connectedAccountsPath());
    }

    private function connectedAccountsPath(): string
    {
        return route('account.profile', absolute: false).'#connected-accounts';
    }

    public function link(Request $request)
    {
        $request->validate(['password' => ['required', 'string', 'max:4096']]);
        $user = $request->user('web');
        if (!$user->password || !Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => 'Autentikasi ulang tidak berhasil.']);
        }
        if ($user->authProviders()->where('provider', 'google')->exists()) return redirect($this->connectedAccountsPath())->with('google_status', 'Akun ini sudah terhubung ke Google.');
        return $this->begin($request, ['purpose' => 'link', 'user_id' => $user->id,
            'password_marker' => hash_hmac('sha256', $user->password, config('app.key'))]);
    }

    private function begin(Request $request, array $flow)
    {
        if (!GoogleOAuth::configured()) return $this->failure($request, 'Login Google belum dikonfigurasi. Gunakan email dan password.');
        try {
            $request->session()->put('google_oauth', $flow + ['started_at' => now()->timestamp]);
            return Socialite::driver('google')->scopes(['openid', 'email', 'profile'])->with(['prompt' => 'select_account'])->redirect();
        } catch (\Throwable) {
            return $this->failure($request);
        }
    }

    public function callback(Request $request)
    {
        $flow = $request->session()->pull('google_oauth');
        $state = $request->session()->get('state');
        if (!GoogleOAuth::configured() || !is_array($flow) || !in_array($flow['purpose'] ?? null, ['login', 'link'], true)
            || !is_int($flow['started_at'] ?? null) || now()->timestamp - $flow['started_at'] < 0 || now()->timestamp - $flow['started_at'] > 300
            || !is_string($state) || $state === '' || !is_string($request->query('state')) || !hash_equals($state, $request->query('state'))) {
            return $this->failure($request, 'Sesi Google berakhir atau tidak valid. Silakan mulai kembali.');
        }
        if ($request->query('error')) return $this->failure($request, 'Login Google dibatalkan atau tidak disetujui.');
        if (!is_string($request->query('code')) || $request->query('code') === '' || strlen($request->query('code')) > 4096) return $this->failure($request);
        $signedIn = false;
        try {
            $actor = $request->user('web');
            if ($flow['purpose'] === 'link') {
                if (!$actor || !$actor->isActive() || $actor->id !== ($flow['user_id'] ?? null)
                    || !is_string($flow['password_marker'] ?? null)
                    || !hash_equals($flow['password_marker'], hash_hmac('sha256', (string) $actor->password, config('app.key')))) return $this->failure($request);
            } elseif ($actor) return $this->failure($request);
            // Stateful Socialite consumes and validates its session state again before exchanging the code.
            $google = Socialite::driver('google')->user();
            $user = app(GoogleIdentity::class)->resolve($google->user, $flow['purpose'] === 'link' ? $actor : null);
            app(QaMfa::class)->assertSatisfied($user, ''); // Existing gate remains intact; MFA stays OFF.
            if ($flow['purpose'] === 'login') {
                Auth::guard('web')->login($user);
                $signedIn = true;
            }
            $request->session()->regenerate();
            app(SessionSecurity::class)->stamp($request, $user);
            if ($flow['purpose'] === 'link') return redirect($this->connectedAccountsPath())->with('google_status', 'Google berhasil dihubungkan.');
            app(SecurityAudit::class)->record('auth.login', $user, ['channel' => 'google']);
            $destination = LoginDestination::for($user)['redirect_to'];
            $returnTo = GoogleOAuth::returnPath($flow['return_to'] ?? '/');
            return redirect($destination === '/' || str_starts_with($returnTo, '/posts/') ? $returnTo : $destination);
        } catch (\Throwable $error) {
            if ($signedIn) app(SessionSecurity::class)->invalidateCurrent($request);
            $message = $error instanceof ValidationException ? ($error->errors()['google'][0] ?? null) : null;
            return $this->failure($request, $message);
        } finally {
            $request->session()->forget('state');
        }
    }

    private function failure(Request $request, ?string $message = null)
    {
        $request->session()->forget(['google_oauth', 'state']);
        // Never log/report provider exceptions: HTTP exceptions may contain codes, tokens or credentials.
        try { app(SecurityAudit::class)->record('auth.failed', null, ['channel' => 'google']); } catch (\Throwable) {}
        return redirect($request->user('web') ? $this->connectedAccountsPath() : '/')->with('google_oauth_message', $message ?? 'Login Google belum berhasil. Silakan coba kembali atau gunakan email dan password.');
    }
}
