<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuickBooksBillsImportTest extends TestCase
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
        $this->assertDatabaseCount('bills', 0);
    }

    public function test_import_creates_bill_lines_and_maps_supplier_and_account_source_ids(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $supplier = $this->supplierFor($user);
        $this->createMapping($user, $connection, 'suppliers', '201', Supplier::class, $supplier->id);

        $account = ChartOfAccount::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Office Supplies Expense',
            'account_type' => 'Expense',
            'detail_type' => 'OfficeSuppliesExpense',
            'is_sub_account' => false,
            'is_active' => true,
        ]);
        $this->createMapping($user, $connection, 'chart_of_accounts', '9001', ChartOfAccount::class, $account->id);
        $this->fakeBills($this->billRecords());

        $response = $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id]);

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'connection_id' => $connection->id,
                'entity_type' => 'bills',
                'branch_id' => $branch->id,
                'fetched_count' => 1,
                'imported_count' => 1,
                'already_imported_count' => 0,
                'skipped_count' => 0,
                'unresolved_line_reference_count' => 0,
            ],
        ]);

        $bill = Bill::query()->where('tenant_id', $user->tenant_id)->firstOrFail();
        $this->assertSame($branch->id, $bill->branch_id);
        $this->assertSame($supplier->id, $bill->supplier_id);
        $this->assertSame($user->id, $bill->created_by);
        $this->assertNull($bill->bill_number, 'DocNumber can be absent in QuickBooks.');
        $this->assertSame('2025-02-05', $bill->bill_date->toDateString());
        $this->assertSame('2025-03-07', $bill->due_date->toDateString());
        $this->assertSame('USD', $bill->currency);
        $this->assertSame('partially_paid', $bill->payment_status);
        $this->assertSame('open', $bill->status);
        $this->assertSame('Historical bill note', $bill->notes);

        $line = BillItem::query()->where('tenant_id', $user->tenant_id)->where('bill_id', $bill->id)->firstOrFail();
        $this->assertSame($account->id, $line->chart_of_account_id);
        $this->assertNull($line->catalog_item_id);
        $this->assertSame('AccountBasedExpenseLineDetail', $line->line_type);
        $this->assertEquals(100.00, $line->amount);
        $this->assertSame('Office supplies', $line->description);
        $this->assertSame('9001', data_get($line->source_data, 'AccountBasedExpenseLineDetail.AccountRef.value'));

        $this->assertDatabaseHas('quickbooks_import_mappings', [
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => 'bills',
            'quickbooks_id' => '401',
            'local_type' => Bill::class,
            'local_id' => $bill->id,
        ]);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/v3/company/9341458483714643/query')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return ($query['query'] ?? null) === 'select * from Bill startposition 1 maxresults 1000';
        });

      $this->assertSame(
    0,
    DB::table('inventories')->count(),
    'Importing a bill must not receive stock.'
);

$this->assertSame(
    0,
    DB::table('inventory_movements')->count(),
    'Importing a bill must not create stock movements.'
);
    }

    public function test_import_is_idempotent_and_preserves_local_bill_edits(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $supplier = $this->supplierFor($user);
        $this->createMapping($user, $connection, 'suppliers', '201', Supplier::class, $supplier->id);
        $this->fakeBills($this->billRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()->assertJsonPath('data.imported_count', 1);

        $bill = Bill::query()->where('tenant_id', $user->tenant_id)->firstOrFail();
        $bill->forceFill(['notes' => 'Locally edited note'])->save();

        $this->fakeBills($this->billRecords());
        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.already_imported_count', 1)
            ->assertJsonPath('data.skipped_count', 0);

        $this->assertSame(1, Bill::query()->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame(1, BillItem::query()->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame('Locally edited note', $bill->fresh()->notes);
    }

    public function test_bill_without_a_mapped_supplier_is_skipped(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $record = $this->billRecords()[0];
        $record['VendorRef']['value'] = 'does-not-exist';
        $this->fakeBills([$record]);

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.skipped_count', 1);

        $this->assertDatabaseCount('bills', 0);
        $this->assertDatabaseCount('bill_items', 0);
        $this->assertDatabaseCount('quickbooks_import_mappings', 0);
    }

    public function test_invalid_provider_bill_list_returns_safe_error_without_importing(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);
        $this->fakeBills(['Bill' => 'not a bill list']);

        $this->actingAs($user)->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('bills', 0);
        $this->assertDatabaseCount('bill_items', 0);
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
        $this->assertDatabaseCount('bills', 0);
    }

    public function test_user_without_migration_import_permission_cannot_import_bills(): void
    {
        $user = $this->userForTenant();
        Permission::findOrCreate('migration.import', 'web');
        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);

        $this->actingAs($user)
            ->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('bills', 0);
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
        $role = Role::findOrCreate('quickbooks-bills-import-test-role', 'web');
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
        // Branch schema fields have evolved during Bizflow development. Fill
        // any required non-default columns based on the installed schema.
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
                in_array($name, ['tenant_id'], true) => $user->tenant_id,
                in_array($name, ['code', 'branch_code'], true) => 'MAIN-'.$user->tenant_id,
                in_array($name, ['slug'], true) => 'main-branch-'.$user->tenant_id,
                in_array($name, ['status'], true) => 'active',
                str_contains($type, 'bool') => true,
                preg_match('/int|serial/', $type) === 1 => 1,
                str_contains($type, 'date') || str_contains($type, 'time') => $now,
                preg_match('/decimal|numeric|real|double|float/', $type) === 1 => 0,
                default => 'Test '.$name,
            };
        }

        // If these conventional columns exist and are required they should be
        // supplied; optional conventional columns can remain at their defaults.
        $id = DB::table('branches')->insertGetId($row);
        return Branch::query()->findOrFail($id);
    }

    private function supplierFor(User $user): Supplier
    {
        return Supplier::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Sandbox Office Supplies',
            'company_name' => 'Sandbox Office Supplies Ltd',
            'email' => 'ap@example.test',
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
        return '/api/integrations/quickbooks/connections/'.$connection->id.'/migration-catalog/bills/import';
    }

    /** @return array<int, array<string, mixed>> */
    private function billRecords(): array
    {
        return [[
            'Id' => '401',
            'VendorRef' => ['value' => '201', 'name' => 'Sandbox Office Supplies'],
            'TxnDate' => '2025-02-05',
            'DueDate' => '2025-03-07',
            'DocNumber' => null,
            'TotalAmt' => 100.00,
            'Balance' => 50.00,
            'CurrencyRef' => ['value' => 'USD'],
            'PrivateNote' => 'Historical bill note',
            'TxnStatus' => 'Payable',
            'Line' => [[
                'Id' => '1',
                'Amount' => 100.00,
                'Description' => 'Office supplies',
                'DetailType' => 'AccountBasedExpenseLineDetail',
                'AccountBasedExpenseLineDetail' => [
                    'AccountRef' => ['value' => '9001', 'name' => 'Office Supplies Expense'],
                ],
            ]],
        ]];
    }

    private function fakeBills(array $rows): void
    {
        // Normal fixtures are a list of Bill records; preserve associative input
        // for the deliberately malformed-provider-response test.
        $queryResponse = array_is_list($rows) ? ['Bill' => $rows] : $rows;

        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/v3/company/*/query*' => Http::response([
                'QueryResponse' => $queryResponse,
            ], 200),
        ]);
    }
}
