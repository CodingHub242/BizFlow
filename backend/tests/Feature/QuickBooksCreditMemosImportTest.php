<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CreditMemo;
use App\Models\CreditMemoApplication;
use App\Models\CreditMemoItem;
use App\Http\Controllers\QuickBooksCreditMemosImportController;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuickBooksCreditMemosImportTest extends TestCase
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

        $this->postJson($this->endpoint($connection))
            ->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_tenant_cannot_import_from_another_tenants_connection(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $otherUser = $this->userForTenant();
        $connection = $this->connectionFor($otherUser);

        $this->actingAs($user)
            ->postJson($this->endpoint($connection))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('credit_memos', 0);
    }

    public function test_import_creates_credit_memo_lines_and_mapping_without_creating_cash_payments(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $connection = $this->connectionFor($user);
        $branch = Branch::factory()->create(['tenant_id' => $user->tenant_id]);
        $customer = $this->customerFor($user);
        $invoice = $this->invoiceFor($user, $customer, 'INV-71');

        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);
        $this->createMapping($user, $connection, 'invoices', '71', Invoice::class, $invoice->id);

        $invoiceTotalBefore = $invoice->fresh()->total;

        $this->fakeCreditMemos($this->creditMemoRecords());

        $response = $this->actingAs($user)->postJson($this->endpoint($connection), [
            'branch_id' => $branch->id,
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'connection_id' => $connection->id,
                'entity_type' => 'credit_memos',
                'fetched_count' => 1,
                'imported_count' => 1,
                'already_imported_count' => 0,
                'skipped_count' => 0,
            ],
        ]);

        $creditMemo = CreditMemo::query()
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $this->assertSame($customer->id, $creditMemo->customer_id);
        $this->assertSame($user->id, $creditMemo->created_by);
        $this->assertSame('CM-73', $creditMemo->credit_memo_number);
        $this->assertSame('2025-02-05', $creditMemo->issued_at->toDateString());
        $this->assertSame('USD', $creditMemo->currency_code);
        $this->assertEquals(150.00, $creditMemo->total);

        $line = CreditMemoItem::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('credit_memo_id', $creditMemo->id)
            ->firstOrFail();

        $this->assertNull($line->catalog_item_id, 'Unmapped historical catalog lines should be preserved.');
        $this->assertSame('Office supplies credit', $line->description);
        $this->assertEquals(1.000, $line->quantity);
        $this->assertEquals(150.00, $line->line_total);
        $this->assertSame('501', data_get($line->source_data, 'SalesItemLineDetail.ItemRef.value'));

        $this->assertDatabaseHas('quickbooks_import_mappings', [
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => 'credit_memos',
            'quickbooks_id' => '73',
            'local_type' => CreditMemo::class,
            'local_id' => $creditMemo->id,
        ]);

        // Importing a Credit Memo is not a cash receipt or an application event.
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseCount('credit_memo_applications', 0);
        $this->assertEquals($invoiceTotalBefore, $invoice->fresh()->total);
    }

    public function test_import_is_idempotent_and_preserves_local_credit_memo_edits(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $connection = $this->connectionFor($user);
        $branch = Branch::factory()->create(['tenant_id' => $user->tenant_id]);
        $customer = $this->customerFor($user);
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);

        $this->fakeCreditMemos($this->creditMemoRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection), [
            'branch_id' => $branch->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 1);

        $creditMemo = CreditMemo::query()->where('tenant_id', $user->tenant_id)->firstOrFail();
        $creditMemo->forceFill(['notes' => 'Edited inside Bizflow'])->save();

        $this->fakeCreditMemos($this->creditMemoRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection), [
            'branch_id' => $branch->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.already_imported_count', 1)
            ->assertJsonPath('data.skipped_count', 0);

        $this->assertSame(1, CreditMemo::query()->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame(1, CreditMemoItem::query()->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame('Edited inside Bizflow', $creditMemo->fresh()->notes);
    }

    public function test_credit_memo_without_a_mapped_customer_is_skipped(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $connection = $this->connectionFor($user);
        $branch = Branch::factory()->create(['tenant_id' => $user->tenant_id]);
        $record = $this->creditMemoRecords()[0];
        $record['CustomerRef']['value'] = 'customer-not-mapped';

        $this->fakeCreditMemos([$record]);

        $this->actingAs($user)->postJson($this->endpoint($connection), [
            'branch_id' => $branch->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.skipped_count', 1);

        $this->assertDatabaseCount('credit_memos', 0);
        $this->assertDatabaseCount('credit_memo_items', 0);
        $this->assertDatabaseCount('quickbooks_import_mappings', 0);
    }

    public function test_invalid_provider_credit_memo_list_returns_safe_error_without_importing(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $connection = $this->connectionFor($user);
        $branch = Branch::factory()->create(['tenant_id' => $user->tenant_id]);
        $this->fakeCreditMemos(['CreditMemo' => 'not a credit memo list']);

        $this->actingAs($user)->postJson($this->endpoint($connection), [
            'branch_id' => $branch->id,
        ])
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('credit_memos', 0);
        $this->assertDatabaseCount('credit_memo_items', 0);
        $this->assertDatabaseCount('quickbooks_import_mappings', 0);
    }

    public function test_user_without_migration_import_permission_cannot_import_credit_memos(): void
    {
        $user = $this->userForTenant();
        Permission::findOrCreate('migration.import', 'web');

        $connection = $this->connectionFor($user);
        $branch = Branch::factory()->create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user)
            ->postJson($this->endpoint($connection), ['branch_id' => $branch->id])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('credit_memos', 0);
    }

    public function test_branch_must_belong_to_the_signed_in_tenant_when_endpoint_requires_a_branch(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $otherUser = $this->userForTenant();
        $connection = $this->connectionFor($user);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherUser->tenant_id]);

        // This assertion applies if the implementation follows the branch-scoped
        // importer contract used by the other QuickBooks transaction importers.
        $response = $this->actingAs($user)->postJson($this->endpoint($connection), [
            'branch_id' => $otherBranch->id,
        ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
        $this->assertDatabaseCount('credit_memos', 0);
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
        $role = Role::findOrCreate('quickbooks-credit-memos-import-test-role', 'web');
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

    private function invoiceFor(User $user, Customer $customer, string $number): Invoice
    {
        $branch = Branch::factory()->create(['tenant_id' => $user->tenant_id]);

        return Invoice::factory()->create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'invoice_number' => $number,
            'total' => 1000,
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
        return '/api/integrations/quickbooks/connections/'
            .$connection->id
            .'/migration-catalog/credit_memos/import';
    }

    private function creditMemoRecords(): array
    {
        return [[
            'Id' => '73',
            'CustomerRef' => ['value' => '101', 'name' => 'Ada Mensah'],
            'TxnDate' => '2025-02-05',
            'DocNumber' => 'CM-73',
            'TotalAmt' => 150.00,
            'RemainingCredit' => 150.00,
            'CurrencyRef' => ['value' => 'USD'],
            'PrivateNote' => 'Historical customer credit',
            'Line' => [[
                'Id' => '1',
                'Amount' => 150.00,
                'Description' => 'Office supplies credit',
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => [
                    'Qty' => 1,
                    'UnitPrice' => 150.00,
                    'ItemRef' => ['value' => '501', 'name' => 'Office supplies'],
                ],
            ]],
        ]];
    }

    private function fakeCreditMemos(array $rows): void
    {
        $queryResponse = array_is_list($rows) ? ['CreditMemo' => $rows] : $rows;

        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/v3/company/*/query*' => Http::response([
                'QueryResponse' => $queryResponse,
            ], 200),
        ]);
    }
}
