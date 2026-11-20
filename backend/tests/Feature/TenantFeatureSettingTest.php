<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantFeatureSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantFeatureSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_tenant_with_enabled_beezbud_setting_returns_true(): void
    {
        $tenant = Tenant::factory()->create();

       $tenant->featureSettings()->updateOrCreate(
    ['feature_key' => TenantFeatureSetting::FEATURE_BEEZBUD],
    ['is_enabled' => true]
);

        $this->assertTrue($tenant->fresh()->isBeezBudEnabled());
    }

    public function test_existing_tenant_with_disabled_beezbud_setting_returns_false(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->featureSettings()->updateOrCreate(
            ['feature_key' => TenantFeatureSetting::FEATURE_BEEZBUD],
            ['is_enabled' => false]
        );

        $this->assertFalse($tenant->fresh()->isBeezBudEnabled());
    }

    public function test_new_tenant_receives_beezbud_enabled_by_default(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertDatabaseHas('tenant_feature_settings', [
            'tenant_id' => $tenant->id,
            'feature_key' => TenantFeatureSetting::FEATURE_BEEZBUD,
            'is_enabled' => true,
        ]);

        $this->assertTrue($tenant->fresh()->isBeezBudEnabled());
    }

    public function test_missing_beezbud_setting_defaults_to_enabled(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->featureSettings()
            ->where('feature_key', TenantFeatureSetting::FEATURE_BEEZBUD)
            ->delete();

        $this->assertTrue($tenant->fresh()->isBeezBudEnabled());
    }

    public function test_tenant_feature_settings_are_isolated_by_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $tenantA->featureSettings()->updateOrCreate(
    ['feature_key' => TenantFeatureSetting::FEATURE_BEEZBUD],
    ['is_enabled' => false]
);

$tenantB->featureSettings()->updateOrCreate(
    ['feature_key' => TenantFeatureSetting::FEATURE_BEEZBUD],
    ['is_enabled' => true]
);

        $this->assertFalse($tenantA->fresh()->isBeezBudEnabled());
        $this->assertTrue($tenantB->fresh()->isBeezBudEnabled());
    }
}
