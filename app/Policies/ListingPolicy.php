<?php

namespace App\Policies;

use App\Models\{Listing,User};

class ListingPolicy
{
    public function update(User $actor, Listing $listing): bool
    {
        return $actor->hasRole('agent')&&$actor->hasPermission('manage_own_listings')&&$actor->id===$listing->agent_id;
    }
    public function delete(User $actor, Listing $listing): bool { return $this->update($actor,$listing); }
    public function viewInternal(User $actor, Listing $listing): bool
    {
        return $this->update($actor,$listing)||($actor->hasRole('branch_manager')&&$actor->hasPermission('view_branch_listings')
            &&$actor->branch_id!==null&&$actor->branch_id===$listing->branch_id);
    }
}
