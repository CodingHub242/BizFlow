<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CreditMemo;
use App\Models\CreditMemoApplication;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuickBooksCreditMemoApplicationsReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.quickbooks.environment' => 'sandbox']);
    }

    public function test_zero_total_quickbooks_payment_creates_credit_application_not_cash_payment(): void
    {
        [$user, $connection, $customer, $invoice, $creditMemo] = $this->fixture();
        $invoiceTotalBefore = (float) $invoice->total;
        $this->fakePayments([$this->applicationPayment()]);

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.skipped_count', 0);

        $this->assertDatabaseHas('credit_memo_applications', [
            'tenant_id' => $user->tenant_id,
            'credit_memo_id' => $creditMemo->id,
            'invoice_id' => $invoice->id,
            'amount_applied' => 150.00,
            'quickbooks_linked_txn_id' => '74',
        ]);
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertEquals($invoiceTotalBefore, $invoice->fresh()->total);
    }

    public function test_reconciliation_is_idempotent_for_the_same_source_payment(): void
    {
        [$user, $connection] = $this->fixture();
        $this->fakePayments([$this->applicationPayment()]);

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()->assertJsonPath('data.created_count', 1);

        $this->fakePayments([$this->applicationPayment()]);
        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.already_applied_count', 1);

        $this->assertDatabaseCount('credit_memo_applications', 1);
        $this->assertDatabaseCount('invoice_payments', 0);
    }

    public function test_customer_mismatch_is_not_applied(): void
    {
        [$user, $connection, $customer, $invoice, $creditMemo] = $this->fixture();
        $otherCustomer = $this->customerFor($user, 'Different customer');
        $creditMemo->forceFill(['customer_id' => $otherCustomer->id])->save();
        $this->fakePayments([$this->applicationPayment()]);

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.skipped_count', 1);

        $this->assertDatabaseCount('credit_memo_applications', 0);
    }

    public function test_mismatched_invoice_and_credit_memo_amounts_are_not_guessed(): void
    {
        [$user, $connection] = $this->fixture();
        $payment = $this->applicationPayment();
        $payment['Line'][1]['Amount'] = 125.00;
        $this->fakePayments([$payment]);

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.skipped_count', 1);

        $this->assertDatabaseCount('credit_memo_applications', 0);
    }

    public function test_user_without_migration_import_permission_cannot_reconcile(): void
    {
        $user = $this->userForTenant();
        Permission::findOrCreate('migration.import', 'web');
        $connection = $this->connectionFor($user);

        $this->actingAs($user)->postJson($this->endpoint($connection))->assertForbidden();
        Http::assertNothingSent();
    }

    private function fixture(): array
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $branch = Branch::factory()->create(['tenant_id' => $user->tenant_id]);
        $customer = $this->customerFor($user);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-71',
            'total' => 1000,
        ]);
        $creditMemo = CreditMemo::query()->create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'credit_memo_number' => 'CM-73',
            'status' => 'open',
            'subtotal' => 150,
            'discount' => 0,
            'tax' => 0,
            'total' => 150,
            'currency_code' => 'USD',
            'issued_at' => '2025-02-05',
            'notes' => null,
        ]);

        $this->createMapping($user, $connection, 'invoices', '71', Invoice::class, $invoice->id);
        $this->createMapping($user, $connection, 'credit_memos', '73', CreditMemo::class, $creditMemo->id);

        return [$user, $connection, $customer, $invoice, $creditMemo];
    }

    private function applicationPayment(): array
    {
        return [
            'Id' => '74',
            'TxnDate' => '2025-02-06',
            'TotalAmt' => 0,
            'CustomerRef' => ['value' => '101', 'name' => 'Ada Mensah'],
            'Line' => [
                ['Amount' => 150.00, 'LinkedTxn' => [['TxnId' => '71', 'TxnType' => 'Invoice']]],
                ['Amount' => 150.00, 'LinkedTxn' => [['TxnId' => '73', 'TxnType' => 'CreditMemo']]],
            ],
        ];
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
        $role = Role::findOrCreate('quickbooks-credit-application-reconcile-test-role', 'web');
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

    private function customerFor(User $user, string $name = 'Ada Mensah'): Customer
    {
        return Customer::query()->create([
            'tenant_id' => $user->tenant_id,
            'name' => $name,
            'company_name' => $name.' Trading',
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'phone' => '+233200000001',
            'address' => 'Accra, Ghana',
            'status' => 'active',
            'notes' => null,
        ]);
    }

    private function createMapping(User $user, QuickBooksConnection $connection, string $entityType, string $sourceId, string $localType, int $localId): QuickBooksImportMapping
    {
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
        return '/api/integrations/quickbooks/connections/'.$connection->id.'/migration-catalog/credit_memos/reconcile-applications';
    }

    private function fakePayments(array $payments): void
    {
        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/v3/company/*/query*' => Http::response([
                'QueryResponse' => ['Payment' => $payments],
            ], 200),
        ]);
    }
}
