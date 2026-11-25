<?php

namespace App\Policies;

use App\Models\{AuditLog, User};

class AuditLogPolicy
{
    public function viewAny(User $user): bool { return $user->isActive()&&$user->hasRole('admin')&&$user->hasPermission('view_audit_logs'); }
    public function view(User $user,AuditLog $record): bool { return $this->viewAny($user); }
}
