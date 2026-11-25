<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\PasswordLoginAttempts;
use App\Support\NewPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'username' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => NewPassword::rules(),
            'device_name' => ['nullable', 'string', 'max:150'],
        ]);

        $customerRole = Role::where('name', 'customer')->firstOrFail();

        $user = User::create([
            'role_id' => $customerRole->id,
            'name' => $validated['name'],
            'username' => $validated['username'] ?? null,
            'email' => strtolower($validated['email']),
            'password' => $validated['password'],
            'status' => 'active',
        ]);

        if (config('security.email_otp')) {
            return response()->json(['message' => 'Buka Promerty di browser untuk masuk dan memverifikasi email.'], 202);
        }

        return response()->json([
            'message' => 'Registrasi berhasil.',
            'token' => $user->createToken(
                $validated['device_name'] ?? 'promerty'
            )->plainTextToken,
            'user' => $user->load('role', 'branch'),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate(['mfa_code'=>['nullable','string','max:20']]);
        $validated = $request->validate([
            'email' => ['required', 'email','max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:150'],
        ]);

        $email=strtolower($validated['email']);
        $guard=Auth::guard('web');
        $valid=app(PasswordLoginAttempts::class)->check($email,
            fn () => $guard->validate(['email'=>$email,'password'=>$validated['password'],'status'=>'active']));
        if (!$valid) {
            app(\App\Services\SecurityAudit::class)->record('auth.failed',null,['channel'=>'api']);
            throw ValidationException::withMessages([
                'email' => PasswordLoginAttempts::DENIED,
            ]);
        }

        $user=$guard->getLastAttempted();
        abort_if(config('security.email_otp') && !$user->email_verified_at, 403,
            'Buka Promerty di browser untuk memverifikasi email.');

        app(\App\Services\QaMfa::class)->assertSatisfied($user,(string)$request->input('mfa_code',''));
        app(\App\Services\SecurityAudit::class)->record('auth.login',$user,['channel'=>'api']);
        return response()->json([
            'message' => 'Login berhasil.',
            ...\App\Support\LoginDestination::for($user),
            'token' => $user->createToken(
                $validated['device_name'] ?? 'promerty'
            )->plainTextToken,
            'user' => $user->load('role', 'branch', 'agentProfile'),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $actor=$request->user();
        $token = $request->user()->currentAccessToken();

        // Sanctum prefers the first-party session when both credentials are sent.
        // A TransientToken represents that session; it is not a database token.
        if ($token instanceof TransientToken) {
            abort_unless($request->hasSession(), 401);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            app(\App\Services\SecurityAudit::class)->record('auth.logout',$actor);

            return response()->json([
                'message' => 'Logout berhasil.',
                'user' => null,
                'csrf_token' => $request->session()->token(),
            ]);
        }

        abort_unless($token instanceof PersonalAccessToken, 401);
        $token->delete();
        app(\App\Services\SecurityAudit::class)->record('auth.logout',$actor);

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $actor=$request->user();
        if (\App\Support\SecurityRollout::enabled()) {
            app(\App\Services\SessionSecurity::class)->revokeAll($request->user());
            if ($request->hasSession()) app(\App\Services\SessionSecurity::class)->invalidateCurrent($request);
            app(\App\Services\SecurityAudit::class)->record('auth.logout_all',$actor);
            return response()->json(['message'=>'Semua sesi dan token berhasil dicabut.',
                'revoked_scope'=>'personal_access_tokens_and_browser_sessions','other_browser_sessions_revoked'=>true]);
        }
        $request->user()->tokens()->delete();
        app(\App\Services\SecurityAudit::class)->record('auth.logout_all',$actor);

        return response()->json([
            // Existing behavior revokes API tokens, not other browser sessions.
            'message' => 'Semua token API berhasil dicabut.',
            'revoked_scope' => 'personal_access_tokens',
            'other_browser_sessions_revoked' => false,
        ]);
    }
}
