<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PhoneAuthController extends Controller
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
        private readonly OtpService $otpService,
    ) {}

    public function requestPublicOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'purpose' => ['required', Rule::in(['login', 'register'])],
        ]);

        try {
            $phone = $this->phoneNormalizer->normalize($validated['phone']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        app(\App\Services\SecurityAudit::class)->publicRequest('auth.otp_requested','phone');
        abort_unless($this->otpService->available(),503,'Layanan SMS OTP belum dikonfigurasi.');
        $existing = User::withTrashed()->where('phone', $phone)->first();

        $eligible = $validated['purpose']==='register' ? !$existing
            : ($existing && !$existing->trashed() && $existing->isActive());
        $this->otpService->issuePublic($phone, $validated['purpose'],(bool)$eligible);

        return response()->json([
            'message' => 'Permintaan OTP diproses melalui kanal pengiriman yang dikonfigurasi.',
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'otp' => ['required', 'digits:'.config('promerty.otp.digits', 6)],
            'name' => ['required', 'string', 'max:150'],
            'username' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'device_name' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $phone = $this->phoneNormalizer->normalize($validated['phone']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        $this->verifyPublicOtp($phone,'register',$validated['otp']);
        if (User::withTrashed()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone'=>'Nomor HP atau OTP tidak valid.']);
        }

        $customerRole = Role::where('name', 'customer')->firstOrFail();

        $user = User::create([
            'role_id' => $customerRole->id,
            'name' => $validated['name'],
            'username' => $validated['username'] ?? null,
            'phone' => $phone,
            'phone_verified_at' => now(),
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Registrasi nomor HP berhasil.',
            'token' => $user->createToken(
                $validated['device_name'] ?? 'promerty'
            )->plainTextToken,
            'user' => $user->load('role'),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate(['mfa_code'=>['nullable','string','max:20']]);
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'otp' => ['required', 'digits:'.config('promerty.otp.digits', 6)],
            'device_name' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $phone = $this->phoneNormalizer->normalize($validated['phone']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        $user = User::where('phone', $phone)->first();

        $this->verifyPublicOtp($phone,'login',$validated['otp']);
        if (!$user || !$user->isActive()) {
            throw ValidationException::withMessages(['phone'=>'Nomor HP atau OTP tidak valid.']);
        }
        abort_if(config('security.email_otp') && $user->email && !$user->email_verified_at, 403,
            'Masuk melalui website untuk memverifikasi email.');
        app(\App\Services\QaMfa::class)->assertSatisfied($user,(string)$request->input('mfa_code',''));
        app(\App\Services\SecurityAudit::class)->record('auth.login',$user,['channel'=>'phone']);

        if (!$user->phone_verified_at) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        return response()->json([
            'message' => 'Login berhasil.',
            'token' => $user->createToken(
                $validated['device_name'] ?? 'promerty'
            )->plainTextToken,
            'user' => $user->load('role', 'branch', 'agentProfile'),
        ]);
    }

    private function verifyPublicOtp(string $phone,string $purpose,string $code): void
    {
        try{$this->otpService->verify($phone,$purpose,$code);}
        catch(ValidationException){
            // Absent, expired, incorrect and exhausted challenges share one public outcome.
            throw ValidationException::withMessages(['phone'=>'Nomor HP atau OTP tidak valid.']);
        }
    }

    public function requestVerifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ]);

        try {
            $phone = $this->phoneNormalizer->normalize($validated['phone']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        $usedByOther = User::withTrashed()->where('phone', $phone)
            ->whereKeyNot($request->user()->id)
            ->exists();

        if ($usedByOther) {
            throw ValidationException::withMessages([
                'phone' => 'Nomor HP sudah digunakan akun lain.',
            ]);
        }

        $this->otpService->issue($phone, 'verify');

        return response()->json([
            'message' => 'Permintaan OTP verifikasi diproses melalui kanal yang dikonfigurasi.',
        ]);
    }

    public function verifyPhone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'otp' => ['required', 'digits:'.config('promerty.otp.digits', 6)],
        ]);

        try {
            $phone = $this->phoneNormalizer->normalize($validated['phone']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        $usedByOther = User::withTrashed()->where('phone', $phone)
            ->whereKeyNot($request->user()->id)
            ->exists();

        if ($usedByOther) {
            throw ValidationException::withMessages([
                'phone' => 'Nomor HP sudah digunakan akun lain.',
            ]);
        }

        $this->otpService->verify($phone, 'verify', $validated['otp']);

        $request->user()->forceFill([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Nomor HP berhasil diverifikasi.',
            'user' => $request->user()->fresh()->load('role', 'branch', 'agentProfile'),
        ]);
    }
}
