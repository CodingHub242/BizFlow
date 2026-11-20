<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantFeatureSetting;
use App\Models\User;
use Database\Seeders\BizFlowRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EngageBeezBudSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        (new BizFlowRolePermissionSeeder())->run();

        $this->tenant = Tenant::factory()->create();

        $seeder = new BizFlowRolePermissionSeeder();
        $seeder->provisionTenantRoles($this->tenant->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->assignRole($this->user, 'Owner');
    }

    private function assignRole(User $user, string $roleName): void
    {
        setPermissionsTeamId($user->tenant_id);

        $role = Role::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('name', $roleName)
            ->firstOrFail();

        $user->assignRole($role);
    }

    public function test_owner_can_view_beezbud_setting(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/engage/settings/beezbud');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.feature', 'beezbud')
            ->assertJsonPath('data.is_enabled', true);
    }

    public function test_owner_can_disable_beezbud(): void
    {
        $response = $this->actingAs($this->user)
            ->patchJson('/api/engage/settings/beezbud', [
                'is_enabled' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_enabled', false);

        $this->assertDatabaseHas('tenant_feature_settings', [
            'tenant_id' => $this->tenant->id,
            'feature_key' => TenantFeatureSetting::FEATURE_BEEZBUD,
            'is_enabled' => false,
        ]);
    }

    public function test_owner_can_enable_beezbud_again(): void
    {
        $this->tenant->featureSettings()->updateOrCreate(
            ['feature_key' => TenantFeatureSetting::FEATURE_BEEZBUD],
            ['is_enabled' => false]
        );

        $response = $this->actingAs($this->user)
            ->patchJson('/api/engage/settings/beezbud', [
                'is_enabled' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.is_enabled', true);
    }

    public function test_tenant_cannot_change_another_tenants_beezbud_setting(): void
    {
        $otherTenant = Tenant::factory()->create();

        $otherSetting = $otherTenant->featureSettings()
            ->where('feature_key', TenantFeatureSetting::FEATURE_BEEZBUD)
            ->firstOrFail();

        $this->actingAs($this->user)
            ->patchJson('/api/engage/settings/beezbud', [
                'is_enabled' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('tenant_feature_settings', [
            'id' => $otherSetting->id,
            'tenant_id' => $otherTenant->id,
            'is_enabled' => true,
        ]);
    }

    public function test_user_without_settings_view_permission_cannot_view_beezbud(): void
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->actingAs($user)
            ->getJson('/api/engage/settings/beezbud')
            ->assertForbidden();
    }

    public function test_user_without_settings_update_permission_cannot_change_beezbud(): void
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->actingAs($user)
            ->patchJson('/api/engage/settings/beezbud', [
                'is_enabled' => false,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('tenant_feature_settings', [
            'tenant_id' => $this->tenant->id,
            'feature_key' => TenantFeatureSetting::FEATURE_BEEZBUD,
            'is_enabled' => true,
        ]);
    }

    public function test_unauthenticated_user_cannot_view_beezbud_settings(): void
    {
        $this->getJson('/api/engage/settings/beezbud')
            ->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_update_beezbud_settings(): void
    {
        $this->patchJson('/api/engage/settings/beezbud', [
            'is_enabled' => false,
        ])->assertUnauthorized();
    }

    public function test_invalid_beezbud_setting_value_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->patchJson('/api/engage/settings/beezbud', [
                'is_enabled' => 'invalid',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_enabled');
    }

    public function test_missing_beezbud_setting_value_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->patchJson('/api/engage/settings/beezbud', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_enabled');
    }
}
