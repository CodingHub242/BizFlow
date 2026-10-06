<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenant\TenantProvisioningService;
use App\TenantStatus;
use App\Services\Onboarding\BusinessOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_onboarding_creates_pending_tenant_and_owner(): void
    {
       $service = app(BusinessOnboardingService::class);

        $result = $service->registerBusiness(
            [
                'name' => 'Onboarding Business',
                'slug' => 'onboarding-business',
                'email' => 'business@example.com',
                'phone' => '0240000020',
                'business_type' => 'Retail',
            ],
            [
                'name' => 'Business Owner',
                'email' => 'owner@example.com',
                'password' => 'password',
            ]
        );

        $tenant = $result['tenant'];
        $owner = $result['owner'];

        $this->assertEquals(
            TenantStatus::PENDING,
            $tenant->status
        );

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Onboarding Business',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $owner->id,
            'tenant_id' => $tenant->id,
            'email' => 'owner@example.com',
        ]);

        $ownerRole = Role::where('name', 'Owner')
            ->where('guard_name', 'web')
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $ownerRole->id,
            'model_id' => $owner->id,
            'model_type' => User::class,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_onboarding_owner_has_owner_permissions(): void
    {
         $service = app(BusinessOnboardingService::class);

        $result = $service->registerBusiness(
            [
                'name' => 'Permission Onboarding',
                'slug' => 'permission-onboarding',
                'email' => 'permission@example.com',
                'phone' => '0240000021',
                'business_type' => 'Retail',
            ],
            [
                'name' => 'Permission Owner',
                'email' => 'permission-owner@example.com',
                'password' => 'password',
            ]
        );

        $tenant = $result['tenant'];
        $owner = $result['owner'];
       

        $this->assertTrue(
            $owner->hasPermissionTo('branches.view')
        );

        $this->assertTrue(
            $owner->hasPermissionTo('products.change_cost')
        );

        $this->assertTrue(
            $owner->hasPermissionTo('migration.import')
        );
    }

    public function test_onboarding_rolls_back_when_provisioning_fails(): void
{
    $provisioning = $this->mock(TenantProvisioningService::class);

    $provisioning
        ->shouldReceive('provision')
        ->once()
        ->andThrow(new \RuntimeException('Provisioning failed'));

    $service = app(BusinessOnboardingService::class);

    $this->expectException(\RuntimeException::class);

    $service->registerBusiness(
        [
            'name' => 'Rollback Business',
            'slug' => 'rollback-business',
            'email' => 'rollback@example.com',
            'phone' => '0240000022',
            'business_type' => 'Retail',
        ],
        [
            'name' => 'Rollback Owner',
            'email' => 'rollback-owner@example.com',
            'password' => 'password',
        ]
    );

    $this->assertDatabaseMissing('tenants', [
        'slug' => 'rollback-business',
    ]);
}
}