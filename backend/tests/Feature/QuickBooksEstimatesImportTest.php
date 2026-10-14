<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuickBooksEstimatesImportTest extends TestCase
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
        $branch = $this->branchFor($user);

        $this->postJson($this->endpoint($connection), ['branch_id' => $branch->id])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_tenant_cannot_import_from_another_tenants_connection(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $otherUser = $this->userForTenant();
        $connection = $this->connectionFor($otherUser);
        $branch = $this->branchFor($user);

        $this->actingAs($user)
            ->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('estimates', 0);
    }

    public function test_import_creates_estimate_lines_and_maps_customer_and_catalog_item(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $customer = $this->customerFor($user);
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);

        $catalogItem = CatalogItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'type' => 'product',
            'name' => 'Office Chair',
            'sku' => 'EST-CHAIR-01',
            'description' => 'Comfortable office chair',
            'unit' => 'piece',
            'cost_price' => 250,
            'selling_price' => 350,
            'tax_rate' => 0,
            'track_inventory' => false,
            'is_active' => true,
        ]);
        $this->createMapping($user, $connection, 'catalog_items', '301', CatalogItem::class, $catalogItem->id);
        $setupService = CatalogItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'type' => 'service',
            'name' => 'Setup Service',
            'sku' => 'EST-SETUP-01',
            'description' => 'Setup service',
            'unit' => 'service',
            'cost_price' => 0,
            'selling_price' => 50,
            'tax_rate' => 0,
            'track_inventory' => false,
            'is_active' => true,
        ]);
        $this->createMapping($user, $connection, 'catalog_items', '302', CatalogItem::class, $setupService->id);
        $this->fakeEstimates($this->estimateRecords());

        $response = $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id]);

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'connection_id' => $connection->id,
                'entity_type' => 'estimates',
                'branch_id' => $branch->id,
                'fetched_count' => 1,
                'imported_count' => 1,
                'already_imported_count' => 0,
                'skipped_count' => 0,
                'unresolved_line_reference_count' => 0,
            ],
        ]);

        $estimate = Estimate::query()->where('tenant_id', $user->tenant_id)->firstOrFail();
        $this->assertSame($branch->id, $estimate->branch_id);
        $this->assertSame($customer->id, $estimate->customer_id);
        $this->assertSame($user->id, $estimate->created_by);
        $this->assertSame('1001', $estimate->estimate_number);
        $this->assertSame('2025-02-05', $estimate->estimate_date->toDateString());
        $this->assertSame('2025-03-07', $estimate->expiry_date->toDateString());
        $this->assertEquals(350.50, $estimate->total);
        $this->assertSame('draft', $estimate->status);
        $this->assertSame('Pending', $estimate->source_status);
        $this->assertSame('USD', $estimate->currency);
        $this->assertSame('Historical estimate note', $estimate->notes);

        $line = EstimateItem::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('estimate_id', $estimate->id)
            ->firstOrFail();
        $this->assertSame($catalogItem->id, $line->catalog_item_id);
        $this->assertSame('SalesItemLineDetail', $line->line_type);
        $this->assertSame('Office Chair', $line->item_name);
        $this->assertSame('Ergonomic chair', $line->description);
        $this->assertEquals(2, $line->quantity);
        $this->assertEquals(150.25, $line->unit_price);
        $this->assertEquals(300.50, $line->line_total);
        $this->assertSame('301', data_get($line->source_data, 'SalesItemLineDetail.ItemRef.value'));

        $this->assertDatabaseHas('quickbooks_import_mappings', [
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => 'estimates',
            'quickbooks_id' => '501',
            'local_type' => Estimate::class,
            'local_id' => $estimate->id,
        ]);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/v3/company/9341458483714643/query')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return ($query['query'] ?? null) === 'select * from Estimate startposition 1 maxresults 1000';
        });

        // Importing an Estimate must not create an invoice, receipt, or stock movement.
        if (Schema::hasTable('invoices')) {
            $this->assertSame(0, DB::table('invoices')->count());
        }
        if (Schema::hasTable('inventories')) {
            $this->assertSame(0, DB::table('inventories')->count());
        }
        if (Schema::hasTable('inventory_movements')) {
            $this->assertSame(0, DB::table('inventory_movements')->count());
        }
    }

    public function test_import_is_idempotent_and_preserves_local_estimate_edits(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $customer = $this->customerFor($user);
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);
        $this->fakeEstimates($this->estimateRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()->assertJsonPath('data.imported_count', 1);

        $estimate = Estimate::query()->where('tenant_id', $user->tenant_id)->firstOrFail();
        $estimate->forceFill(['notes' => 'Edited inside Bizflow'])->save();

        $this->fakeEstimates($this->estimateRecords());
        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.already_imported_count', 1)
            ->assertJsonPath('data.skipped_count', 0);

        $this->assertSame(1, Estimate::query()->where('tenant_id', $user->tenant_id)->count());
$this->assertSame(2, EstimateItem::query()->where('tenant_id', $user->tenant_id)->count());       
 $this->assertSame('Edited inside Bizflow', $estimate->fresh()->notes);
    }

    public function test_duplicate_document_numbers_use_source_ids_as_identity(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $customer = $this->customerFor($user);
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);
        $first = $this->estimateRecords()[0];
        $second = $first;
        $second['Id'] = '502';
        $this->fakeEstimates([$first, $second]);

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.fetched_count', 2)
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.skipped_count', 0);

        $this->assertSame(2, Estimate::query()->where('tenant_id', $user->tenant_id)->where('estimate_number', '1001')->count());
        $this->assertDatabaseHas('quickbooks_import_mappings', [
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => 'estimates',
            'quickbooks_id' => '501',
        ]);
        $this->assertDatabaseHas('quickbooks_import_mappings', [
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => 'estimates',
            'quickbooks_id' => '502',
        ]);
    }

    public function test_estimate_without_a_mapped_customer_is_skipped(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $record = $this->estimateRecords()[0];
        $record['CustomerRef']['value'] = 'missing-customer';
        $this->fakeEstimates([$record]);

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.skipped_count', 1);

        $this->assertDatabaseCount('estimates', 0);
        $this->assertDatabaseCount('estimate_items', 0);
        $this->assertDatabaseCount('quickbooks_import_mappings', 0);
    }

    public function test_invalid_provider_estimate_list_returns_safe_error_without_importing(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $this->fakeEstimates(['Estimate' => 'not an estimate list']);

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('estimates', 0);
        $this->assertDatabaseCount('estimate_items', 0);
        $this->assertDatabaseCount('quickbooks_import_mappings', 0);
    }

    public function test_branch_must_belong_to_the_signed_in_tenant(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $otherUser = $this->userForTenant();
        $otherBranch = $this->branchFor($otherUser);

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $otherBranch->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
        $this->assertDatabaseCount('estimates', 0);
    }

    public function test_user_without_migration_import_permission_cannot_import_estimates(): void
    {
        $user = $this->userForTenant();
        Permission::findOrCreate('migration.import', 'web');
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('estimates', 0);
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
        $role = Role::findOrCreate('quickbooks-estimates-import-test-role', 'web');
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

    private function branchFor(User $user): Branch
    {
        $now = now();
        $row = [
            'tenant_id' => $user->tenant_id,
            'name' => 'Main Branch',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach (Schema::getColumns('branches') as $column) {
            $name = $column['name'];
            if (array_key_exists($name, $row) || in_array($name, ['id', 'deleted_at'], true)) {
                continue;
            }
            $hasDefault = array_key_exists('default', $column) && $column['default'] !== null;
            $nullable = (bool) ($column['nullable'] ?? true);
            $autoIncrement = (bool) ($column['auto_increment'] ?? false);
            if ($nullable || $hasDefault || $autoIncrement) {
                continue;
            }

            $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? 'string'));
            $row[$name] = match (true) {
                in_array($name, ['created_by', 'updated_by', 'owner_id', 'manager_id'], true) => $user->id,
                $name === 'tenant_id' => $user->tenant_id,
                in_array($name, ['code', 'branch_code'], true) => 'MAIN-'.$user->tenant_id,
                $name === 'slug' => 'main-branch-'.$user->tenant_id,
                $name === 'status' => 'active',
                str_contains($type, 'bool') => true,
                preg_match('/int|serial/', $type) === 1 => 1,
                str_contains($type, 'date') || str_contains($type, 'time') => $now,
                preg_match('/decimal|numeric|real|double|float/', $type) === 1 => 0,
                default => 'Test '.$name,
            };
        }

        $id = DB::table('branches')->insertGetId($row);
        return Branch::query()->findOrFail($id);
    }

    private function customerFor(User $user): Customer
    {
        return Customer::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Ada Mensah',
            'company_name' => 'Ada Mensah Trading',
            'email' => 'ada@example.test',
            'phone' => '+233200000001',
            'address' => 'Accra, Ghana',
            'status' => 'active',
            'notes' => null,
        ]);
    }

    private function createMapping(
        User $user,
        QuickBooksConnection $connection,
        string $entityType,
        string $sourceId,
        string $localType,
        int $localId,
    ): QuickBooksImportMapping {
        return QuickBooksImportMapping::query()->create([
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => $entityType,
            'quickbooks_id' => $sourceId,
            'local_type' => $localType,
            'local_id' => $localId,
            'payload_hash' => null,
            'imported_at' => now(),
            'metadata' => [],
        ]);
    }

    private function endpoint(QuickBooksConnection $connection): string
    {
        return '/api/integrations/quickbooks/connections/'.$connection->id.'/migration-catalog/estimates/import';
    }

    /** @return array<int, array<string, mixed>> */
    private function estimateRecords(): array
    {
        return [[
            'Id' => '501',
            'CustomerRef' => ['value' => '101', 'name' => 'Ada Mensah'],
            'TxnDate' => '2025-02-05',
            'ExpirationDate' => '2025-03-07',
            'DocNumber' => '1001',
            'TotalAmt' => 350.50,
            'CurrencyRef' => ['value' => 'USD'],
            'PrivateNote' => 'Historical estimate note',
            'TxnStatus' => 'Pending',
            'TxnTaxDetail' => ['TotalTax' => 0],
            'Line' => [[
                'Id' => '1',
                'Amount' => 300.50,
                'Description' => 'Ergonomic chair',
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => [
                    'ItemRef' => ['value' => '301', 'name' => 'Office Chair'],
                    'Qty' => 2,
                    'UnitPrice' => 150.25,
                ],
            ], [
                'Id' => '2',
                'Amount' => 50.00,
                'Description' => 'Additional service fee',
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => [
                    'ItemRef' => ['value' => '302', 'name' => 'Setup Service'],
                    'Qty' => 1,
                    'UnitPrice' => 50.00,
                ],
            ]],
        ]];
    }

    private function fakeEstimates(array $rows): void
    {
        // Preserve deliberately malformed associative inputs for safe-error tests.
        $queryResponse = array_is_list($rows) ? ['Estimate' => $rows] : $rows;

        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/v3/company/*/query*' => Http::response([
                'QueryResponse' => $queryResponse,
            ], 200),
        ]);
    }
}
