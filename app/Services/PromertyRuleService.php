<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PromertyRuleService
{
    public function ensureVerifiedAgent(User $user): void
    {
        if (!$user->hasRole('agent')) {
            throw ValidationException::withMessages([
                'agent' => 'Akun ini bukan akun Agent.',
            ]);
        }

        if (!$user->agentProfile?->isVerified()) {
            throw ValidationException::withMessages([
                'agent' => 'Agent belum diverifikasi dan belum dapat membuat listing atau post.',
            ]);
        }

        if (!$user->branch_id) {
            throw ValidationException::withMessages([
                'branch' => 'Agent harus terhubung ke satu cabang.',
            ]);
        }
    }

    public function validateListingTarget(
        string $listingType,
        ?int $projectId,
        ?int $secondaryPropertyId
    ): void {
        $validPrimary = $listingType === 'primary'
            && $projectId !== null
            && $secondaryPropertyId === null;

        $validSecondary = $listingType === 'secondary'
            && $projectId === null
            && $secondaryPropertyId !== null;

        if (!$validPrimary && !$validSecondary) {
            throw ValidationException::withMessages([
                'listing_type' => 'Primary harus memilih project. Secondary harus memilih secondary property.',
            ]);
        }
    }

    public function ensureSecondaryAvailableForBranch(
        int $secondaryPropertyId,
        int $branchId,
        ?int $ignoreListingId = null
    ): void {
        $query = Listing::query()
            ->where('listing_type', 'secondary')
            ->where('secondary_property_id', $secondaryPropertyId)
            ->where('branch_id', $branchId);

        if ($ignoreListingId) {
            $query->whereKeyNot($ignoreListingId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'secondary_property_id' =>
                    'Properti secondary ini sudah dipegang Agent lain pada cabang yang sama.',
            ]);
        }
    }

    public function validatePostMedia(string $contentType, array $media): void
    {
        $count = count($media);

        if ($contentType === 'photo' && ($count < 1 || $count > (int)config('agent.media_max_count'))) {
            throw ValidationException::withMessages([
                'media' => 'Jumlah foto pada post harus berada dalam batas listing.',
            ]);
        }

        if ($contentType === 'video' && $count !== 1) {
            throw ValidationException::withMessages([
                'media' => 'Post video harus berisi tepat 1 video.',
            ]);
        }

        foreach ($media as $item) {
            $type = $item['media_type'] ?? null;

            if ($contentType === 'photo' && $type !== 'image') {
                throw ValidationException::withMessages([
                    'media' => 'Post foto hanya boleh berisi gambar.',
                ]);
            }

            if ($contentType === 'video' && $type !== 'video') {
                throw ValidationException::withMessages([
                    'media' => 'Post video hanya boleh berisi video.',
                ]);
            }
        }
    }
}
