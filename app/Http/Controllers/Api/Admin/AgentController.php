<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentProfile;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgentController extends Controller
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
    ) {}

    public function pending(): JsonResponse
    {
        $agents = User::query()
            ->whereHas('role', fn ($q) => $q->where('name', 'agent'))
            ->whereHas('agentProfile', fn ($q) => $q->where('verification_status', 'pending'))
            ->with('branch.agency', 'agentProfile')
            ->latest()
            ->paginate(20);

        return response()->json($agents);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'username' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'professional_name' => ['nullable', 'string', 'max:150'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'verification_note' => ['nullable', 'string', 'max:1000'],
            'password' => ['prohibited'],
            'password_confirmation' => ['prohibited'],
        ]);

        if (empty($validated['email']) && empty($validated['phone'])) {
            throw ValidationException::withMessages([
                'email' => 'Isi minimal email atau nomor HP Agent.',
            ]);
        }

        $phone = null;

        if (!empty($validated['phone'])) {
            try {
                $phone = $this->phoneNormalizer->normalize($validated['phone']);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['phone' => $e->getMessage()]);
            }

            if (User::withTrashed()->where('phone', $phone)->exists()) {
                throw ValidationException::withMessages([
                    'phone' => 'Nomor HP sudah digunakan.',
                ]);
            }
        }

        $branch = Branch::findOrFail($validated['branch_id']);
        $agentRole = Role::where('name', 'agent')->firstOrFail();

        $user = DB::transaction(function () use ($validated, $phone, $agentRole, $branch) {
            $user = User::create([
                'role_id' => $agentRole->id,
                'branch_id' => $branch->id,
                'name' => $validated['name'],
                'username' => $validated['username'] ?? null,
                'email' => isset($validated['email']) ? strtolower($validated['email']) : null,
                'phone' => $phone,
                'status' => 'active',
            ]);

            AgentProfile::create([
                'user_id' => $user->id,
                'professional_name' => $validated['professional_name'] ?? null,
                'license_number' => $validated['license_number'] ?? null,
                'verification_status' => 'pending',
                'verification_note' => $validated['verification_note'] ?? null,
            ]);

            return $user;
        });

        return response()->json([
            'message' => 'Akun Agent dibuat dan menunggu verifikasi.',
            'agent' => $user->load('branch.agency', 'agentProfile'),
        ], 201);
    }

    public function verify(Request $request, User $user): JsonResponse
    {
        $request->validate(['verification_note'=>['nullable','string','max:1000']]);
        if (!$user->hasRole('agent') || !$user->agentProfile) {
            abort(422, 'User bukan Agent.');
        }

        DB::transaction(fn()=>$user->agentProfile->update([
            'verification_status' => 'verified',
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'verification_note' => $request->input(
                'verification_note',
                $user->agentProfile->verification_note
            ),
        ]));

        return response()->json([
            'message' => 'Agent berhasil diverifikasi.',
            'agent' => $user->fresh()->load('branch.agency', 'agentProfile'),
        ]);
    }

    public function reject(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'verification_note' => ['required', 'string', 'max:1000'],
        ]);

        if (!$user->hasRole('agent') || !$user->agentProfile) {
            abort(422, 'User bukan Agent.');
        }

        $user->agentProfile->update([
            'verification_status' => 'rejected',
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'verification_note' => $validated['verification_note'],
        ]);

        return response()->json([
            'message' => 'Verifikasi Agent ditolak.',
            'agent' => $user->fresh()->load('branch.agency', 'agentProfile'),
        ]);
    }
}
