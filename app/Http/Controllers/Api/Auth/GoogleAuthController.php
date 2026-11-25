<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Google\Client as GoogleClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GoogleAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate(['mfa_code'=>['nullable','string','max:20']]);
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:150'],
        ]);

        $clientId = config('promerty.google_client_id');

        if (!$clientId) {
            abort(500, 'GOOGLE_CLIENT_ID belum dikonfigurasi.');
        }

        try {
            $client = app(GoogleClient::class);
            $client->setClientId($clientId);
            $payload = $client->verifyIdToken($validated['id_token']);
        } catch (\Throwable) {
            $payload = false; // Never report token-verification exceptions or the submitted token.
        }

        if (!$payload || empty($payload['sub']) || empty($payload['email'])) {
            throw ValidationException::withMessages([
                'id_token' => 'Google ID token tidak valid.',
            ]);
        }

        if (($payload['email_verified'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'id_token' => 'Email Google belum diverifikasi.',
            ]);
        }

        try { $user = app(\App\Services\GoogleIdentity::class)->resolve($payload); }
        catch (\Illuminate\Database\QueryException) {
            throw ValidationException::withMessages(['id_token' => 'Login Google belum berhasil. Silakan mulai kembali.']);
        }

        if (!$user->isActive()) {
            throw ValidationException::withMessages([
                'email' => 'Akun sedang tidak aktif.',
            ]);
        }

        app(\App\Services\QaMfa::class)->assertSatisfied($user,(string)$request->input('mfa_code',''));
        app(\App\Services\SecurityAudit::class)->record('auth.login',$user,['channel'=>'google']);
        return response()->json([
            'message' => 'Login Google berhasil.',
            'token' => $user->createToken(
                $validated['device_name'] ?? 'promerty'
            )->plainTextToken,
            'user' => $user->load('role', 'branch', 'agentProfile'),
        ]);
    }
}
