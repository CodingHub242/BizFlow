<?php

namespace App\Services\Platform;

use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;

class PlatformAuditService
{
    public function record(PlatformAdmin $admin,string $action,string $targetType,int $targetId,?string $reason = null,?array $metadata = null): PlatformAuditLog 
    {
        return PlatformAuditLog::create([
            'platform_admin_id' => $admin->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}