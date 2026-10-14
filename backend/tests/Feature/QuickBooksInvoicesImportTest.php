<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Customer;
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

class QuickBooksInvoicesImportTest extends TestCase
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

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])->assertNotFound();
        Http::assertNothingSent();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_import_creates_invoice_lines_and_maps_customer_and_catalog_item(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $customer = $this->customerFor($user);
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);

        $item = $this->createMappedInvoiceItems($user, $connection);
        $this->fakeInvoices($this->invoiceRecords());

        $response = $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id]);
        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'connection_id' => $connection->id,
                'entity_type' => 'invoices',
                'branch_id' => $branch->id,
                'fetched_count' => 1,
                'imported_count' => 1,
                'already_imported_count' => 0,
                'skipped_count' => 0,
                'unresolved_line_reference_count' => 0,
            ],
        ]);

        $invoice = DB::table('invoices')->where('tenant_id', $user->tenant_id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame((string) $customer->id, (string) $invoice->customer_id);
        $this->assertSame((string) $branch->id, (string) $invoice->branch_id);
        if (Schema::hasColumn('invoices', 'invoice_number')) {
            $this->assertSame('INV-1001', $invoice->invoice_number);
        }
        foreach (['invoice_date', 'issue_date', 'date'] as $dateColumn) {
            if (Schema::hasColumn('invoices', $dateColumn)) {
                $this->assertSame('2025-02-05', substr((string) $invoice->{$dateColumn}, 0, 10));
                break;
            }
        }
        if (Schema::hasColumn('invoices', 'total')) {
            $this->assertEquals(350.50, $invoice->total);
        } elseif (Schema::hasColumn('invoices', 'total_amount')) {
            $this->assertEquals(350.50, $invoice->total_amount);
        }
        if (Schema::hasColumn('invoices', 'balance_due')) {
            $this->assertEquals(150.00, $invoice->balance_due);
        } elseif (Schema::hasColumn('invoices', 'balance')) {
            $this->assertEquals(150.00, $invoice->balance);
        }

        $this->assertSame(2, DB::table($this->lineTable())->where('invoice_id', $invoice->id)->count());
        if (Schema::hasColumn($this->lineTable(), 'catalog_item_id')) {
            $this->assertDatabaseHas($this->lineTable(), [
                'invoice_id' => $invoice->id,
                'catalog_item_id' => $item->id,
            ]);
        }
        $this->assertDatabaseHas('quickbooks_import_mappings', [
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => 'invoices',
            'quickbooks_id' => '601',
            'local_type' => \App\Models\Invoice::class,
            'local_id' => $invoice->id,
        ]);

        // Importing invoices does not create payment records or stock movements.
        foreach (['invoice_payments', 'payments', 'inventory_movements'] as $table) {
            if (Schema::hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count());
            }
        }
    }

    public function test_subtotal_and_discount_lines_do_not_require_catalog_mappings(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $customer = $this->customerFor($user);
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);
        $this->createMappedInvoiceItems($user, $connection);

        $records = $this->invoiceRecords();
        $records[0]['Line'][] = [
            'Amount' => 350.50,
            'DetailType' => 'SubTotalLineDetail',
            'SubTotalLineDetail' => [],
        ];
        $records[0]['Line'][] = [
            'Amount' => -10.00,
            'DetailType' => 'DiscountLineDetail',
            'DiscountLineDetail' => ['PercentBased' => false],
        ];
        $this->fakeInvoices($records);

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.skipped_count', 0)
            ->assertJsonPath('data.unresolved_line_reference_count', 0);

        $invoice = DB::table('invoices')->where('tenant_id', $user->tenant_id)->firstOrFail();
        $lineCount = DB::table($this->lineTable())->where('invoice_id', $invoice->id)->count();
        $this->assertSame($this->lineCatalogItemIdIsRequired() ? 2 : 4, $lineCount);

        // The local invoice-line schema cannot store summary/discount lines when
        // catalog_item_id is NOT NULL, so ensure their original payload is retained.
        $mapping = DB::table('quickbooks_import_mappings')
            ->where('tenant_id', $user->tenant_id)
            ->where('entity_type', 'invoices')
            ->where('quickbooks_id', '601')
            ->firstOrFail();
        $metadata = json_decode((string) $mapping->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(4, $metadata['source_line_count']);
        $this->assertSame('SubTotalLineDetail', $metadata['source_lines'][2]['DetailType']);
        $this->assertSame('DiscountLineDetail', $metadata['source_lines'][3]['DetailType']);
    }

    public function test_invoice_with_unmapped_catalog_item_is_skipped_safely_when_foreign_key_is_required(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $customer = $this->customerFor($user);
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);

        $chair = CatalogItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'type' => 'product',
            'name' => 'Office Chair',
            'sku' => 'QB-INV-CHAIR-'.$user->tenant_id,
            'description' => 'Office chair',
            'unit' => 'piece',
            'cost_price' => 250,
            'selling_price' => 350,
            'tax_rate' => 0,
            'track_inventory' => false,
            'is_active' => true,
        ]);
        $this->createMapping($user, $connection, 'catalog_items', '301', CatalogItem::class, $chair->id);
        $this->fakeInvoices($this->invoiceRecords());

        $response = $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id]);
        if ($this->lineCatalogItemIdIsRequired()) {
            $response->assertOk()
                ->assertJsonPath('data.imported_count', 0)
                ->assertJsonPath('data.skipped_count', 1)
                ->assertJsonPath('data.unresolved_line_reference_count', 1);
            $this->assertSame(0, DB::table('invoices')->where('tenant_id', $user->tenant_id)->count());
            $this->assertSame(0, QuickBooksImportMapping::query()->where('tenant_id', $user->tenant_id)->where('entity_type', 'invoices')->count());
        } else {
            $response->assertOk()
                ->assertJsonPath('data.imported_count', 1)
                ->assertJsonPath('data.skipped_count', 0)
                ->assertJsonPath('data.unresolved_line_reference_count', 1);
        }
    }

    public function test_import_is_idempotent_and_preserves_local_invoice_edits(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $customer = $this->customerFor($user);
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);
        $this->createMappedInvoiceItems($user, $connection);
        $this->fakeInvoices($this->invoiceRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()->assertJsonPath('data.imported_count', 1);
        $invoice = DB::table('invoices')->where('tenant_id', $user->tenant_id)->firstOrFail();
        if (Schema::hasColumn('invoices', 'notes')) {
            DB::table('invoices')->where('id', $invoice->id)->update(['notes' => 'Edited inside Bizflow']);
        }
        $lineCount = DB::table($this->lineTable())->where('invoice_id', $invoice->id)->count();

        $this->fakeInvoices($this->invoiceRecords());
        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.already_imported_count', 1)
            ->assertJsonPath('data.skipped_count', 0);

        $this->assertSame(1, DB::table('invoices')->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame($lineCount, DB::table($this->lineTable())->where('invoice_id', $invoice->id)->count());
        if (Schema::hasColumn('invoices', 'notes')) {
            $this->assertSame('Edited inside Bizflow', DB::table('invoices')->where('id', $invoice->id)->value('notes'));
        }
    }

    public function test_duplicate_document_numbers_use_quickbooks_source_ids_as_identity(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $customer = $this->customerFor($user);
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);
        $this->createMappedInvoiceItems($user, $connection);
        $first = $this->invoiceRecords()[0];
        $second = $first;
        $second['Id'] = '602';
        $this->fakeInvoices([$first, $second]);

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.fetched_count', 2)
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.skipped_count', 0);

        $this->assertSame(2, DB::table('invoices')->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame(2, DB::table('quickbooks_import_mappings')->where('tenant_id', $user->tenant_id)->where('entity_type', 'invoices')->count());
        if (Schema::hasColumn('invoices', 'invoice_number')) {
            $numbers = DB::table('invoices')->where('tenant_id', $user->tenant_id)->orderBy('id')->pluck('invoice_number')->all();
            $this->assertNotSame($numbers[0], $numbers[1]);
        }
    }

    public function test_invoice_without_a_mapped_customer_is_skipped(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $this->fakeInvoices($this->invoiceRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.skipped_count', 1);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_invalid_provider_invoice_list_returns_safe_error_without_importing(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $this->fakeInvoices(['Invoice' => 'not an invoice list']);

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertStatus(502)
            ->assertJsonPath('success', false);
        $this->assertDatabaseCount('invoices', 0);
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
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_user_without_migration_import_permission_cannot_import_invoices(): void
    {
        $user = $this->userForTenant();
        Permission::findOrCreate('migration.import', 'web');
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('invoices', 0);
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
        $role = Role::findOrCreate('quickbooks-invoices-import-test-role', 'web');
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
        $row = ['tenant_id' => $user->tenant_id, 'name' => 'Main Branch', 'created_at' => $now, 'updated_at' => $now];
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
            'tenant_id' => $user->tenant_id, 'name' => 'Ada Mensah', 'company_name' => 'Ada Mensah Trading',
            'email' => 'ada@example.test', 'phone' => '+233200000001', 'address' => 'Accra, Ghana', 'status' => 'active', 'notes' => null,
        ]);
    }

    private function createMapping(User $user, QuickBooksConnection $connection, string $entityType, string $sourceId, string $localType, int $localId): QuickBooksImportMapping
    {
        return QuickBooksImportMapping::query()->create([
            'tenant_id' => $user->tenant_id, 'quickbooks_connection_id' => $connection->id,
            'entity_type' => $entityType, 'quickbooks_id' => $sourceId, 'local_type' => $localType,
            'local_id' => $localId, 'payload_hash' => null, 'imported_at' => now(), 'metadata' => [],
        ]);
    }

    private function endpoint(QuickBooksConnection $connection): string
    {
        return '/api/integrations/quickbooks/connections/'.$connection->id.'/migration-catalog/invoices/import';
    }

    private function lineTable(): string
    {
        return Schema::hasTable('invoice_items') ? 'invoice_items' : 'invoice_lines';
    }

    private function createMappedInvoiceItems(User $user, QuickBooksConnection $connection): CatalogItem
    {
        $chair = CatalogItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'type' => 'product',
            'name' => 'Office Chair',
            'sku' => 'QB-INV-CHAIR-'.$user->tenant_id,
            'description' => 'Office chair',
            'unit' => 'piece',
            'cost_price' => 250,
            'selling_price' => 350,
            'tax_rate' => 0,
            'track_inventory' => false,
            'is_active' => true,
        ]);
        $this->createMapping($user, $connection, 'catalog_items', '301', CatalogItem::class, $chair->id);

        $service = CatalogItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'type' => 'service',
            'name' => 'Setup Service',
            'sku' => 'QB-INV-SETUP-'.$user->tenant_id,
            'description' => 'Setup service',
            'unit' => 'service',
            'cost_price' => 0,
            'selling_price' => 50,
            'tax_rate' => 0,
            'track_inventory' => false,
            'is_active' => true,
        ]);
        $this->createMapping($user, $connection, 'catalog_items', '302', CatalogItem::class, $service->id);

        return $chair;
    }

    private function lineCatalogItemIdIsRequired(): bool
    {
        if (! Schema::hasColumn($this->lineTable(), 'catalog_item_id')) {
            return false;
        }
        foreach (Schema::getColumns($this->lineTable()) as $column) {
            if (($column['name'] ?? null) === 'catalog_item_id') {
                return ! (bool) ($column['nullable'] ?? false);
            }
        }
        return true;
    }

    private function invoiceRecords(): array
    {
        return [[
            'Id' => '601',
            'CustomerRef' => ['value' => '101', 'name' => 'Ada Mensah'],
            'TxnDate' => '2025-02-05',
            'DueDate' => '2025-03-07',
            'DocNumber' => 'INV-1001',
            'TotalAmt' => 350.50,
            'Balance' => 150.00,
            'CurrencyRef' => ['value' => 'USD'],
            'PrivateNote' => 'Historical invoice note',
            'TxnTaxDetail' => ['TotalTax' => 0],
            'Line' => [[
                'Id' => '1', 'Amount' => 300.50, 'Description' => 'Ergonomic chair',
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => ['ItemRef' => ['value' => '301', 'name' => 'Office Chair'], 'Qty' => 2, 'UnitPrice' => 150.25],
            ], [
                'Id' => '2', 'Amount' => 50.00, 'Description' => 'Additional service fee',
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => ['ItemRef' => ['value' => '302', 'name' => 'Setup Service'], 'Qty' => 1, 'UnitPrice' => 50.00],
            ]],
        ]];
    }

    private function fakeInvoices(array $rows): void
    {
        $queryResponse = array_is_list($rows) ? ['Invoice' => $rows] : $rows;
        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/v3/company/*/query*' => Http::response([
                'QueryResponse' => $queryResponse,
            ], 200),
        ]);
    }
}
