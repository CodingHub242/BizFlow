<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\TenantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantApprovalMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_tenant_can_access_protected_core_route(): void
    {
        $user = $this->createUserForTenant(TenantStatus::APPROVED);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/inventory');

        $response->assertSuccessful();
    }

    public function test_pending_tenant_is_blocked_from_core_access(): void
    {
        $user = $this->createUserForTenant(TenantStatus::PENDING);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/inventory');

        $response
            ->assertStatus(403)
            ->assertJson([
                'message' => 'Your business registration is awaiting approval.',
                'code' => 'TENANT_PENDING',
            ]);
    }

    public function test_rejected_tenant_is_blocked_from_core_access(): void
    {
        $user = $this->createUserForTenant(TenantStatus::REJECTED);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/inventory');

        $response
            ->assertStatus(403)
            ->assertJson([
                'message' => 'Your business registration was not approved.',
                'code' => 'TENANT_REJECTED',
            ]);
    }

    public function test_suspended_tenant_is_blocked_from_core_access(): void
    {
        $user = $this->createUserForTenant(TenantStatus::SUSPENDED);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/inventory');

        $response
            ->assertStatus(403)
            ->assertJson([
                'message' => 'Your business account has been suspended.',
                'code' => 'TENANT_SUSPENDED',
            ]);
    }


    private function createUserForTenant(TenantStatus $status): User
    {
        $tenant = Tenant::create([
            'name' => 'Test Business',
            'slug' => 'test-business-' . strtolower($status->value),
            'email' => $status->value . '@example.com',
            'phone' => '0240000040',
            'business_type' => 'Retail',
            'status' => $status,
        ]);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'user-' . strtolower($status->value) . '@example.com',
            'password' => Hash::make('password'),
            'tenant_id' => $tenant->id,
        ]);

        $provisioning = app(\App\Services\Tenant\TenantProvisioningService::class);

$provisioning->provision($tenant);
$provisioning->assignOwner($tenant, $user);

        return $user;
    }
}