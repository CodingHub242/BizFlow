<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuickBooksCatalogItemsImportTest extends TestCase
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
        $this->assertDatabaseCount('catalog_items', 0);
    }

    public function test_import_maps_inventory_noninventory_and_service_items(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakeItems($this->itemRecords());

        $response = $this->actingAs($user)->postJson($this->endpoint($connection));

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'connection_id' => $connection->id,
                'entity_type' => 'catalog_items',
                'fetched_count' => 4,
                'imported_count' => 3,
                'already_imported_count' => 0,
                'skipped_count' => 1,
            ],
        ]);

        $chair = CatalogItem::query()->where('tenant_id', $user->tenant_id)->where('sku', 'CHAIR-001')->firstOrFail();
        $this->assertSame('Office Chair', $chair->name);
       $this->assertSame('product', $chair->type->value);
        $this->assertSame('Comfortable office chair', $chair->description);
        $this->assertSame('piece', $chair->unit);
        $this->assertEquals(250.00, $chair->cost_price);
        $this->assertEquals(350.50, $chair->selling_price);
        $this->assertTrue((bool) $chair->track_inventory);
        $this->assertTrue((bool) $chair->is_active);
        $this->assertEquals(0, $chair->tax_rate, 'Taxable is a flag, not a tax percentage.');

        $service = CatalogItem::query()->where('tenant_id', $user->tenant_id)->where('name', 'Installation Service')->firstOrFail();
       $this->assertSame('service', $service->type->value);
        $this->assertFalse((bool) $service->track_inventory);

        $nonInventory = CatalogItem::query()->where('tenant_id', $user->tenant_id)->where('name', 'Cleaning Supplies')->firstOrFail();
       $this->assertSame('product', $nonInventory->type->value);
        $this->assertFalse((bool) $nonInventory->track_inventory);
        $this->assertFalse((bool) $nonInventory->is_active);

        $this->assertDatabaseHas('quickbooks_import_mappings', [
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => 'catalog_items',
            'quickbooks_id' => '301',
            'local_type' => CatalogItem::class,
            'local_id' => $chair->id,
        ]);

        $mapping = QuickBooksImportMapping::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('quickbooks_connection_id', $connection->id)
            ->where('entity_type', 'catalog_items')
            ->where('quickbooks_id', '301')
            ->firstOrFail();
        $this->assertSame(true, data_get($mapping->metadata, 'source_taxable'));
        $this->assertSame(10, data_get($mapping->metadata, 'source_qty_on_hand'));

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/v3/company/9341458483714643/query')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return ($query['query'] ?? null) === 'select * from Item startposition 1 maxresults 1000';
        });

        $this->assertDatabaseMissing('catalog_items', [
            'tenant_id' => $user->tenant_id,
            'name' => 'Furniture',
        ]);
    }

    public function test_import_is_idempotent_and_preserves_local_catalog_edits(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakeItems($this->itemRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 3);

        $item = CatalogItem::query()->where('tenant_id', $user->tenant_id)->where('sku', 'CHAIR-001')->firstOrFail();
        $item->forceFill(['name' => 'Bizflow-edited chair'])->save();

        $this->fakeItems($this->itemRecords());
        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.already_imported_count', 3)
            ->assertJsonPath('data.skipped_count', 1);

        $this->assertSame(3, CatalogItem::query()->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame('Bizflow-edited chair', $item->fresh()->name);
        $this->assertSame(3, QuickBooksImportMapping::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('quickbooks_connection_id', $connection->id)
            ->where('entity_type', 'catalog_items')
            ->count());
    }

    public function test_invalid_provider_item_list_returns_safe_error_without_importing(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakeItems(['Item' => 'not an item list']);

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('catalog_items', 0);
        $this->assertDatabaseCount('quickbooks_import_mappings', 0);
    }

    public function test_import_skips_skus_already_used_by_an_existing_bizflow_item(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        CatalogItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Existing local chair',
            'type' => 'product',
            'sku' => 'CHAIR-001',
            'cost_price' => 20,
            'selling_price' => 30,
            'tax_rate' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ]);
        $this->fakeItems($this->itemRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.skipped_count', 2);

        $this->assertDatabaseMissing('quickbooks_import_mappings', [
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => 'catalog_items',
            'quickbooks_id' => '301',
        ]);
    }

    public function test_user_without_migration_import_permission_cannot_import_items(): void
    {
        $user = $this->userForTenant();
        Permission::findOrCreate('migration.import', 'web');
        $connection = $this->connectionFor($user);

        $this->actingAs($user)->postJson($this->endpoint($connection))->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('catalog_items', 0);
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
        $role = Role::findOrCreate('quickbooks-catalog-item-import-test-role', 'web');
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
        return '/api/integrations/quickbooks/connections/'.$connection->id.'/migration-catalog/catalog_items/import';
    }

    /** @return array<int, array<string, mixed>> */
    private function itemRecords(): array
    {
        return [
            [
                'Id' => '301',
                'Name' => 'Office Chair',
                'Type' => 'Inventory',
                'Sku' => 'CHAIR-001',
                'SalesDesc' => 'Comfortable office chair',
                'UnitPrice' => 350.50,
                'PurchaseCost' => 250.00,
                'QtyOnHand' => 10,
                'UnitOfMeasureSetRef' => ['name' => 'piece'],
                'Taxable' => true,
                'Active' => true,
                'IncomeAccountRef' => ['value' => '79', 'name' => 'Sales of Product Income'],
                'ExpenseAccountRef' => ['value' => '80', 'name' => 'Cost of Goods Sold'],
                'AssetAccountRef' => ['value' => '81', 'name' => 'Inventory Asset'],
            ],
            [
                'Id' => '302',
                'Name' => 'Installation Service',
                'Type' => 'Service',
                'Description' => 'Installation labor',
                'UnitPrice' => 150.00,
                'PurchaseCost' => 50.00,
                'Active' => true,
            ],
            [
                'Id' => '303',
                'Name' => 'Cleaning Supplies',
                'Type' => 'NonInventory',
                'Sku' => 'CLEAN-001',
                'UnitPrice' => 25.00,
                'PurchaseCost' => 12.50,
                'Active' => false,
            ],
            [
                'Id' => '304',
                'Name' => 'Furniture',
                'Type' => 'Category',
                'Active' => true,
            ],
        ];
    }

    private function fakeItems(array $rows): void
    {
        // Normal fixtures are a list of Item records. Preserve associative
        // input for the deliberately malformed-provider-response test.
        $queryResponse = array_is_list($rows) ? ['Item' => $rows] : $rows;

        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/v3/company/*/query*' => Http::response([
                'QueryResponse' => $queryResponse,
            ], 200),
        ]);
    }
}
