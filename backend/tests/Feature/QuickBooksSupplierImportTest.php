<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuickBooksSupplierImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.quickbooks.environment' => 'sandbox']);
    }

    public function test_import_requires_authentication(): void
    {
        $user = $this->userForTenant();
        $connection = $this->connectionFor($user);

        $this->postJson($this->endpoint($connection))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_tenant_cannot_import_from_another_tenants_connection(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $otherUser = $this->userForTenant();
        $connection = $this->connectionFor($otherUser);

        $this->actingAs($user)->postJson($this->endpoint($connection))->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('suppliers', 0);
    }

    public function test_import_creates_suppliers_and_maps_quickbooks_source_ids(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakeSuppliers($this->supplierRecords());

        $response = $this->actingAs($user)->postJson($this->endpoint($connection));

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'connection_id' => $connection->id,
                'entity_type' => 'suppliers',
                'fetched_count' => 2,
                'imported_count' => 2,
                'already_imported_count' => 0,
                'skipped_count' => 0,
            ],
        ]);

        $supplier = Supplier::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('name', 'Accra Office Supplies Ltd')
            ->firstOrFail();

        $this->assertSame('sales@accraoffice.example', $supplier->email);
        $this->assertSame('+233244000001', $supplier->phone);
        $this->assertSame('12 Spintex Road, Accra, Greater Accra, Ghana', $supplier->address);
        $this->assertSame('active', $supplier->status);

        $this->assertDatabaseHas('quickbooks_import_mappings', [
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => 'suppliers',
            'quickbooks_id' => '101',
            'local_type' => Supplier::class,
            'local_id' => $supplier->id,
        ]);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/v3/company/9341458483714643/query')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return ($query['query'] ?? null) === 'select * from Vendor startposition 1 maxresults 1000';
        });
    }

    public function test_import_is_idempotent_and_preserves_local_supplier_edits(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakeSuppliers($this->supplierRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 2);

        $supplier = Supplier::query()->where('tenant_id', $user->tenant_id)->where('name', 'Accra Office Supplies Ltd')->firstOrFail();
        $supplier->forceFill(['name' => 'Bizflow-edited name'])->save();

        $this->fakeSuppliers($this->supplierRecords());
        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.already_imported_count', 2);

        $this->assertSame(2, Supplier::query()->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame('Bizflow-edited name', $supplier->fresh()->name);
        $this->assertSame(2, QuickBooksImportMapping::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('quickbooks_connection_id', $connection->id)
            ->where('entity_type', 'suppliers')
            ->count());
    }

    public function test_invalid_provider_supplier_list_returns_safe_error_without_importing(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakeSuppliers(['Vendor' => 'not a supplier list']);

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('quickbooks_import_mappings', 0);
    }

    public function test_user_without_migration_import_permission_cannot_import_suppliers(): void
    {
        $user = $this->userForTenant();
        Permission::findOrCreate('migration.import', 'web');
        $connection = $this->connectionFor($user);

        $this->actingAs($user)->postJson($this->endpoint($connection))->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('suppliers', 0);
    }

    private function userForTenant(): User
    {
        $tenant = Tenant::factory()->create();
        return User::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function grantMigrationImportPermission(User $user): void
    {
        setPermissionsTeamId((int) $user->tenant_id);
        $permission = Permission::findOrCreate('migration.import', 'web');
        $role = Role::findOrCreate('quickbooks-supplier-import-test-role', 'web');
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        setPermissionsTeamId((int) $user->tenant_id);
    }

    private function connectionFor(User $user): QuickBooksConnection
    {
        return QuickBooksConnection::query()->create([
            'tenant_id' => $user->tenant_id,
            'connected_by' => $user->id,
            'realm_id' => '9341458483714643',
            'company_name' => 'Sandbox Company US ca89',
            'connection_status' => 'connected',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'connected_at' => now(),
        ]);
    }

    private function endpoint(QuickBooksConnection $connection): string
    {
        return '/api/integrations/quickbooks/connections/'.$connection->id.'/migration-catalog/suppliers/import';
    }

    /** @return array<int, array<string, mixed>> */
    private function supplierRecords(): array
    {
        return [
            [
                'Id' => '101',
                'DisplayName' => 'Accra Office Supplies Ltd',
                'CompanyName' => 'Accra Office Supplies Ltd Trading',
                'PrimaryEmailAddr' => ['Address' => 'sales@accraoffice.example'],
                'PrimaryPhone' => ['FreeFormNumber' => '+233244000001'],
                'BillAddr' => [
                    'Line1' => '12 Spintex Road',
                    'City' => 'Accra',
                    'CountrySubDivisionCode' => 'Greater Accra',
                    'Country' => 'Ghana',
                ],
                'Active' => true,
                'Notes' => 'Sandbox supplier',
            ],
            [
                'Id' => '102',
                'DisplayName' => 'Kofi Boateng',
                'CompanyName' => 'Kofi Building Materials',
                'Active' => false,
            ],
        ];
    }

    private function fakeSuppliers(array $rows): void
    {
        // Standard fixtures are a list of Supplier records. Keep associative
        // input unchanged for the deliberately malformed-response test.
        $queryResponse = array_is_list($rows) ? ['Vendor' => $rows] : $rows;

        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/v3/company/*/query*' => Http::response([
                'QueryResponse' => $queryResponse,
            ], 200),
        ]);
    }
}
