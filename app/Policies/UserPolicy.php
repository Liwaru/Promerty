<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view(User $actor, User $target): bool
    {
        if($actor->id===$target->id)return true;
        if($actor->hasRole('admin')&&$actor->hasPermission('manage_users'))return !$target->hasRole('owner');
        return $actor->hasRole('branch_manager')&&$actor->hasPermission('view_branch_agents')
            &&$actor->branch_id!==null&&$actor->branch_id===$target->branch_id&&$target->hasRole('agent');
    }
    public function update(User $actor, User $target): bool
    {
        if($actor->id===$target->id)return true;
        return $actor->hasRole('admin')&&$actor->hasPermission('manage_users')
            &&in_array($target->role?->name,['customer','agent','branch_manager'],true);
    }
    public function delete(User $actor, User $target): bool
    {
        return $actor->id!==$target->id&&$this->update($actor,$target);
    }

    public function initiatePasswordReset(User $actor, User $target): bool
    {
        return $actor->isActive() && $actor->hasRole('admin')
            && $actor->id !== $target->id && $this->update($actor, $target)
            && in_array($target->role?->name, ['customer', 'agent', 'branch_manager'], true);
    }
}
