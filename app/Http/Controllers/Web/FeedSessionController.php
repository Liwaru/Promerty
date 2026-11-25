<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{Role, User};
use App\Services\PasswordLoginAttempts;
use App\Support\NewPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class FeedSessionController extends Controller
{
    public function me(Request $request)
    {
        $user = $request->user('web');
        if ($user && !$user->isActive()) { Auth::guard('web')->logout(); $request->session()->invalidate(); $request->session()->regenerateToken(); $user = null; }
        return response()->json(['user'=>$user ? ['id'=>$user->id,'name'=>$user->name,'role'=>$user->role?->name,'phone_verified'=>(bool)$user->phone_verified_at,
            'incoming_surveys'=>$user->hasRole('agent') && $user->agentProfile?->isVerified()
                && $user->branch?->status==='active' && $user->hasPermission('handle_own_surveys')] : null, 'csrf_token'=>csrf_token()]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email'=>['required','email','max:255'], 'password'=>['required','string']]);
        $request->validate(['mfa_code'=>['nullable','string','max:20']]);
        $credentials['email'] = strtolower($credentials['email']);
        $valid = app(PasswordLoginAttempts::class)->check($credentials['email'],
            fn () => Auth::guard('web')->validate([...$credentials, 'status'=>'active']));
        if (!$valid) {
            app(\App\Services\SecurityAudit::class)->record('auth.failed',null,['channel'=>'web']);
            throw ValidationException::withMessages(['email'=>PasswordLoginAttempts::DENIED]);
        }
        $guard=Auth::guard('web');
        $actor=$guard->getLastAttempted();
        if (config('security.email_otp') && !$actor->email_verified_at) {
            return $this->requireEmailOtp($request, $actor);
        }
        if (config('hashing.rehash_on_login', true)) {
            $guard->getProvider()->rehashPasswordIfRequired($actor, $credentials);
        }
        return $this->completeLogin($request, $actor, (string) $request->input('mfa_code', ''));
    }

    public function completeLogin(Request $request, User $actor, #[\SensitiveParameter] string $mfaCode = '')
    {
        Auth::guard('web')->login($actor);
        try { app(\App\Services\QaMfa::class)->assertSatisfied($actor,$mfaCode); }
        catch (\Throwable $e) { app(\App\Services\SessionSecurity::class)->invalidateCurrent($request); throw $e; }
        $request->session()->regenerate();
        app(\App\Services\SessionSecurity::class)->stamp($request, $request->user('web'));
        app(\App\Services\SecurityAudit::class)->record('auth.login',$request->user('web'),['channel'=>'web']);
        return response()->json($this->me($request)->getData(true)+\App\Support\LoginDestination::for($request->user('web')));
    }

    public function register(Request $request)
    {
        abort_if(config('security.email_otp') && Auth::guard('web')->check(), 409);
        $request->merge(['email'=>strtolower((string)$request->input('email'))]);
        $data = $request->validate(['name'=>['required','string','max:150'],'email'=>['required','email','max:255','unique:users,email'],'password'=>NewPassword::rules()]);
        $user = User::create(['name'=>$data['name'],'email'=>$data['email'],'password'=>$data['password'],'role_id'=>Role::where('name','customer')->firstOrFail()->id,'status'=>'active']);
        if (config('security.email_otp')) {
            return $this->requireEmailOtp($request, $user);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        app(\App\Services\SessionSecurity::class)->stamp($request, $user);
        return $this->me($request);
    }

    private function requireEmailOtp(Request $request, User $user)
    {
        // Pending verification must never hold an authenticated guard session.
        abort_unless(Auth::guard('web')->guest(), 409);
        if ($request->session()->get('email_otp.login.id') !== $user->id) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        $result = app(EmailOtpController::class)->start($request, $user, \App\Services\EmailOtp::LOGIN);
        $returnTo = $request->input('return_to');
        if (is_string($returnTo) && preg_match('~\A/posts/[1-9][0-9]{0,18}\z~D', $returnTo)) {
            $request->session()->put('email_otp.login.return_to', $returnTo);
        }
        $request->session()->flash('otp_notice', $result['error'] ?? 'Kode verifikasi dikirim ke email akun Anda.');
        $request->session()->flash('otp_retry', $result['retry'] ?? 0);

        return response()->json(['verification_url' => route('email-otp.show', 'login', false)], 202);
    }

    public function logout(Request $request)
    {
        $actor=$request->user('web');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        app(\App\Services\SecurityAudit::class)->record('auth.logout',$actor);
        if (!$request->expectsJson()) return redirect('/');
        return response()->json(['user'=>null,'csrf_token'=>csrf_token()]);
    }
}
