<?php

namespace App\Services\Platform;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Support\Facades\DB;
use App\TenantStatus;

class BusinessApprovalService
{
    public function __construct(
    private readonly PlatformAuditService $auditService,
    ) {
    }

    public function approve(Tenant $tenant,PlatformAdmin $admin): void 
    {
        if ($tenant->status !== TenantStatus::PENDING) {
            throw new \DomainException(
                'Only pending businesses can be approved.'
            );
        }

        DB::transaction(function () use ($tenant, $admin) {
            $tenant->update([
                'status' => TenantStatus::APPROVED,
                'approved_at' => now(),
                'approved_by' => $admin->id,
            ]);

            $this->auditService->record(
                $admin,
                'business.approved',
                Tenant::class,
                $tenant->id,
            );
        });
    }

    public function reject(Tenant $tenant,PlatformAdmin $admin,string $reason): void 
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException(
                'A rejection reason is required.'
            );
        }

        if ($tenant->status !== TenantStatus::PENDING) {
            throw new \DomainException(
                'Only pending businesses can be rejected.'
            );
        }
        DB::transaction(function () use ($tenant, $admin,$reason) {
            $tenant->update([
                'status' => TenantStatus::REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $admin->id,
                'rejection_reason' => $reason,
            ]);

            $this->auditService->record(
                $admin,
                'business.rejected',
                Tenant::class,
                $tenant->id,
                $reason,
            );
        });
    }

    public function suspend(Tenant $tenant,PlatformAdmin $admin,string $reason): void 
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException(
                'A suspension reason is required.'
            );
        }

        if ($tenant->status !== TenantStatus::APPROVED) {
            throw new \DomainException(
                'Only approved businesses can be suspended.'
            );
        }

        DB::transaction(function () use ($tenant, $admin, $reason) {
            $tenant->update([
                'status' => TenantStatus::SUSPENDED,
                'suspended_at' => now(),
                'suspended_by' => $admin->id,
                'suspension_reason' => $reason,
            ]);

            $this->auditService->record(
                $admin,
                'business.suspended',
                Tenant::class,
                $tenant->id,
                $reason,
            );
        });
    }
}