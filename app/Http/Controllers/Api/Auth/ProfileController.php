<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->load(
                'role.permissions',
                'branch.agency',
                'agentProfile'
            ),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'username' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:30'],
            'photo' => ['sometimes', 'nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('photo')) {
            if ($user->photo && !str_starts_with($user->photo, 'http')) {
                Storage::disk('public')->delete($user->photo);
            }

            $validated['photo'] = $request->file('photo')
                ->store('profile-photos', 'public');
        }

        $user->fill($validated)->save();

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user' => $user->fresh()->load('role', 'branch.agency', 'agentProfile'),
        ]);
    }
}
