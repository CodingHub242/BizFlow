<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use App\ExpensePaymentMethod;
use App\Models\Tenant;
use App\Models\User;
use App\Http\Controllers\QuickBooksExpensesImportController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuickBooksExpensesImportTest extends TestCase
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

        $this->postJson($this->endpoint($connection), [
            'branch_id' => $branch->id,
        ])->assertUnauthorized();

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
            ->postJson($this->endpoint($connection), [
                'branch_id' => $branch->id,
            ])
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_import_creates_expense_and_mapping_without_inventory_movements(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);

        $this->fakePurchases($this->purchaseRecords());

        $response = $this->actingAs($user)->postJson(
            $this->endpoint($connection),
            ['branch_id' => $branch->id],
        );

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'connection_id' => $connection->id,
                'entity_type' => 'expenses',
                'branch_id' => $branch->id,
                'fetched_count' => 1,
                'imported_count' => 1,
                'already_imported_count' => 0,
                'skipped_count' => 0,
                'unresolved_category_reference_count' => 1,
            ],
        ]);

        $expense = Expense::query()
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $this->assertSame($branch->id, $expense->branch_id);
        $this->assertSame($user->id, $expense->created_by);
        $this->assertNull($expense->category_id);
        $this->assertSame('2025-02-05', $expense->expense_date->toDateString());
        $this->assertSame('Office supplies', $expense->description);
        $this->assertSame('INV-EXP-401', $expense->reference);
       $this->assertSame(
            ExpensePaymentMethod::BANK_TRANSFER,
            $expense->payment_method
        );
        $this->assertEquals(100.00, $expense->amount);

        $this->assertDatabaseHas('quickbooks_import_mappings', [
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => 'expenses',
            'quickbooks_id' => '401',
            'local_type' => Expense::class,
            'local_id' => $expense->id,
        ]);

        $mapping = QuickBooksImportMapping::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('quickbooks_connection_id', $connection->id)
            ->where('entity_type', 'expenses')
            ->where('quickbooks_id', '401')
            ->firstOrFail();

        $this->assertSame(
            '9001',
            data_get($mapping->metadata, 'source_account_references.0.quickbooks_account_id'),
        );

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/v3/company/9341458483714643/query')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['query'] ?? null)
                === 'select * from Purchase startposition 1 maxresults 1000';
        });

        if (Schema::hasTable('inventories')) {
            $this->assertSame(0, DB::table('inventories')->count());
        }

        if (Schema::hasTable('inventory_movements')) {
            $this->assertSame(0, DB::table('inventory_movements')->count());
        }
    }

    public function test_import_is_idempotent_and_preserves_local_expense_edits(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);

        $this->fakePurchases($this->purchaseRecords());

        $this->actingAs($user)
            ->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 1);

        $expense = Expense::query()
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $expense->forceFill([
            'description' => 'Locally edited expense',
            'notes' => 'Do not overwrite this note',
        ])->save();

        $this->fakePurchases($this->purchaseRecords());

        $this->actingAs($user)
            ->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.already_imported_count', 1)
            ->assertJsonPath('data.skipped_count', 0);

        $this->assertSame(
            1,
            Expense::query()->where('tenant_id', $user->tenant_id)->count(),
        );

        $this->assertSame('Locally edited expense', $expense->fresh()->description);
        $this->assertSame('Do not overwrite this note', $expense->fresh()->notes);
        $this->assertDatabaseCount('quickbooks_import_mappings', 1);
    }

    public function test_purchase_with_invalid_required_fields_is_skipped(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);

        $records = $this->purchaseRecords();
        $records[0]['TotalAmt'] = 0;

        $this->fakePurchases($records);

        $this->actingAs($user)
            ->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.skipped_diagnostics.0.reason', 'missing_or_invalid_required_fields');

        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('quickbooks_import_mappings', 0);
    }

    public function test_invalid_provider_purchase_list_returns_safe_error_without_importing(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);

        $this->fakePurchases(['Purchase' => 'not a purchase list']);

        $this->actingAs($user)
            ->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('quickbooks_import_mappings', 0);
    }

    public function test_branch_must_belong_to_the_signed_in_tenant(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $connection = $this->connectionFor($user);
        $otherUser = $this->userForTenant();
        $otherBranch = $this->branchFor($otherUser);

        $this->actingAs($user)
            ->postJson($this->endpoint($connection), [
                'branch_id' => $otherBranch->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_user_without_migration_import_permission_cannot_import_expenses(): void
    {
        $user = $this->userForTenant();

        Permission::findOrCreate('migration.import', 'web');

        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);

        $this->actingAs($user)
            ->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_credit_card_purchase_maps_to_card_payment_method(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $connection = $this->connectionFor($user);
        $branch = $this->branchFor($user);

        $record = $this->purchaseRecords()[0];
        $record['PaymentType'] = 'CreditCard';
        $record['CreditCardPayment'] = [
            'CreditCardAccountRef' => [
                'value' => '77',
                'name' => 'Company Credit Card',
            ],
        ];

        $this->fakePurchases([$record]);

        $this->actingAs($user)
            ->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 1);

        $this->assertDatabaseHas('expenses', [
            'tenant_id' => $user->tenant_id,
            'branch_id' => $branch->id,
            'payment_method' => 'card',
            'amount' => '100.00',
        ]);
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
        $role = Role::findOrCreate('quickbooks-expenses-import-test-role', 'web');
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
                in_array($name, ['code', 'branch_code'], true) => 'MAIN-' . $user->tenant_id,
                $name === 'slug' => 'main-branch-' . $user->tenant_id,
                $name === 'status' => 'active',
                str_contains($type, 'bool') => true,
                preg_match('/int|serial/', $type) === 1 => 1,
                str_contains($type, 'date') || str_contains($type, 'time') => $now,
                preg_match('/decimal|numeric|real|double|float/', $type) === 1 => 0,
                default => 'Test ' . $name,
            };
        }

        $id = DB::table('branches')->insertGetId($row);

        return Branch::query()->findOrFail($id);
    }

    private function endpoint(QuickBooksConnection $connection): string
    {
        return '/api/integrations/quickbooks/connections/'
            . $connection->id
            . '/migration-catalog/expenses/import';
    }

    private function purchaseRecords(): array
    {
        return [[
            'Id' => '401',
            'PaymentType' => 'Check',
            'PaymentRefNum' => 'CHK-401',
            'TxnDate' => '2025-02-05',
            'DocNumber' => 'INV-EXP-401',
            'TotalAmt' => 100.00,
            'PrivateNote' => null,
            'EntityRef' => [
                'value' => '201',
                'name' => 'Office Supplies Store',
                'type' => 'Vendor',
            ],
            'Line' => [[
                'Id' => '1',
                'Amount' => 100.00,
                'Description' => 'Office supplies',
                'DetailType' => 'AccountBasedExpenseLineDetail',
                'AccountBasedExpenseLineDetail' => [
                    'AccountRef' => [
                        'value' => '9001',
                        'name' => 'Office Supplies Expense',
                    ],
                ],
            ]],
        ]];
    }

    private function fakePurchases(array $rows): void
    {
        // Normal fixtures are a list of Purchase records. Preserve
        // associative input for malformed-provider-response tests.
        $queryResponse = array_is_list($rows)
            ? ['Purchase' => $rows]
            : $rows;

        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/v3/company/*/query*' => Http::response([
                'QueryResponse' => $queryResponse,
            ], 200),
        ]);
    }
}
