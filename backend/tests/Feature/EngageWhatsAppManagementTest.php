<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\EngageWhatsAppAccount;
use App\Models\EngageWhatsAppNumber;
use Database\Seeders\BizFlowRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EngageWhatsAppManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private EngageWhatsAppAccount $account;
    private EngageWhatsAppNumber $number;

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

        setPermissionsTeamId($this->tenant->id);

        $ownerRole = Role::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'Owner')
            ->where('guard_name', 'web')
            ->firstOrFail();

        $this->user->assignRole($ownerRole);

        $this->account = EngageWhatsAppAccount::create([
            'tenant_id' => $this->tenant->id,
            'waba_id' => 'test-waba-123',
            'name' => 'Test Business',
            'status' => 'active',
        ]);

        $this->number = EngageWhatsAppNumber::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_account_id' => $this->account->id,
            'phone_number_id' => '123456789',
            'display_phone_number' => '+233241234567',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($this->user);
    }

    public function test_owner_can_list_whatsapp_accounts(): void
    {
        $this->getJson('/api/engage/whatsapp/accounts')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_owner_can_view_whatsapp_account(): void
    {
        $this->getJson(
            "/api/engage/whatsapp/accounts/{$this->account->id}"
        )
            ->assertOk()
            ->assertJsonPath('data.id', $this->account->id);
    }

    public function test_owner_can_list_whatsapp_numbers(): void
    {
        $this->getJson('/api/engage/whatsapp/numbers')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_owner_can_set_default_whatsapp_number(): void
    {
        $secondNumber = EngageWhatsAppNumber::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_account_id' => $this->account->id,
            'phone_number_id' => '987654321',
            'display_phone_number' => '+233501234567',
            'status' => 'active',
            'is_default' => false,
        ]);

        $this->postJson(
            "/api/engage/whatsapp/numbers/{$secondNumber->id}/default"
        )->assertOk();

        $this->assertDatabaseHas('engage_whatsapp_numbers', [
            'id' => $this->number->id,
            'is_default' => false,
        ]);

        $this->assertDatabaseHas('engage_whatsapp_numbers', [
            'id' => $secondNumber->id,
            'is_default' => true,
        ]);
    }

    public function test_inactive_account_number_cannot_be_default(): void
    {
        $this->account->update(['status' => 'inactive']);

        $this->postJson(
            "/api/engage/whatsapp/numbers/{$this->number->id}/default"
        )->assertNotFound();
    }

    public function test_owner_can_deactivate_whatsapp_account(): void
    {
        $this->patchJson(
            "/api/engage/whatsapp/accounts/{$this->account->id}/status",
            ['status' => 'inactive']
        )->assertOk();

        $this->assertDatabaseHas('engage_whatsapp_accounts', [
            'id' => $this->account->id,
            'status' => 'inactive',
        ]);
    }

    public function test_owner_can_deactivate_whatsapp_number(): void
    {
        $this->patchJson(
            "/api/engage/whatsapp/numbers/{$this->number->id}/status",
            ['status' => 'inactive']
        )->assertOk();

        $this->assertDatabaseHas('engage_whatsapp_numbers', [
            'id' => $this->number->id,
            'status' => 'inactive',
            'is_default' => false,
        ]);
    }

    public function test_cannot_view_another_tenants_whatsapp_account(): void
    {
        $otherTenant = Tenant::factory()->create();

        $otherAccount = EngageWhatsAppAccount::create([
            'tenant_id' => $otherTenant->id,
            'waba_id' => 'other-waba',
            'name' => 'Other Business',
            'status' => 'active',
        ]);

        $this->getJson(
            "/api/engage/whatsapp/accounts/{$otherAccount->id}"
        )->assertNotFound();
    }

    public function test_user_without_manage_permission_cannot_change_default(): void
    {
        $salesperson = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $salespersonRole = Role::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'Salesperson')
            ->where('guard_name', 'web')
            ->firstOrFail();

        $salesperson->assignRole($salespersonRole);

        $this->actingAs($salesperson);

        $this->postJson(
            "/api/engage/whatsapp/numbers/{$this->number->id}/default"
        )->assertForbidden();
    }
}
