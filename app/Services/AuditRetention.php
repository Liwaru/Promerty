<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\SecurityRollout;

class AuditRetention
{
    /** QA exercise only; production retention/scheduling needs a separate rollout. */
    public function pruneQa(): int
    {
        abort_unless(SecurityRollout::enabled(),404);
        $days=max(1,min(365,(int)config('security.audit_retention_days',90)));
        return AuditLog::where('created_at','<',now()->subDays($days))->whereIn('action',SecurityAudit::events())->delete();
    }
}
