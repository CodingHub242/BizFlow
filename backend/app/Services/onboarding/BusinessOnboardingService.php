<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenant\TenantProvisioningService;
use App\TenantStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessOnboardingService
{
    public function __construct(
        private readonly TenantProvisioningService $tenantProvisioning,
    ) {
    }

    /**
     * Register a new business and its owner.
     *
     * The business remains pending until approved
     * by the platform.
     */
    public function registerBusiness(array $businessData, array $ownerData): array
    {
        return DB::transaction(function () use ($businessData, $ownerData) {
            $tenant = Tenant::create([
                'name' => $businessData['name'],
                'slug' => $businessData['slug'] ?? Str::slug($businessData['name']),
                'email' => $businessData['email'] ?? null,
                'phone' => $businessData['phone'] ?? null,
                'business_type' => $businessData['business_type'] ?? null,
                'logo_path' => $businessData['logo_path'] ?? null,
                'status' => TenantStatus::PENDING,
            ]);

            $this->tenantProvisioning->provision($tenant);

            $owner = User::create([
                'tenant_id' => $tenant->id,
                'name' => $ownerData['name'],
                'email' => $ownerData['email'],
                'password' => $ownerData['password'],
            ]);

            $this->tenantProvisioning->assignOwner(
                $tenant,
                $owner
            );

            return [
                'tenant' => $tenant->fresh(),
                'owner' => $owner->fresh(),
            ];
        });
    }
}