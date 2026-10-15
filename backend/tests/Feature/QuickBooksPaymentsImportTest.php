<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
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

class QuickBooksPaymentsImportTest extends TestCase
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
        $otherUser = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($otherUser);

        $this->actingAs($user)->postJson($this->endpoint($connection))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_import_creates_payment_and_maps_quickbooks_source_id_without_changing_invoice_aggregate(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $customer = $this->customerFor($user);
        $invoice = $this->invoiceFor($user, $customer, 'INV-1001');
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);
        $this->createMapping($user, $connection, 'invoices', '601', Invoice::class, $invoice->id);

        if (Schema::hasColumn('invoices', 'amount_paid')) {
            DB::table('invoices')->where('id', $invoice->id)->update(['amount_paid' => 200]);
        }
        if (Schema::hasColumn('invoices', 'balance_due')) {
            DB::table('invoices')->where('id', $invoice->id)->update(['balance_due' => 800]);
        }
        $paidBefore = Schema::hasColumn('invoices', 'amount_paid')
            ? DB::table('invoices')->where('id', $invoice->id)->value('amount_paid') : null;
        $balanceBefore = Schema::hasColumn('invoices', 'balance_due')
            ? DB::table('invoices')->where('id', $invoice->id)->value('balance_due') : null;

        $this->fakePayments($this->paymentRecords());
        $response = $this->actingAs($user)->postJson($this->endpoint($connection));

        $response->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.connection_id', $connection->id)
            ->assertJsonPath('data.entity_type', 'payments')
            ->assertJsonPath('data.fetched_count', 1)
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.already_imported_count', 0)
            ->assertJsonPath('data.skipped_count', 0)
            ->assertJsonPath('data.allocation_count', 1)
            ->assertJsonPath('data.unresolved_invoice_reference_count', 0);

        $payment = DB::table('invoice_payments')->where('tenant_id', $user->tenant_id)->firstOrFail();
        $this->assertSame($invoice->id, (int) $payment->invoice_id);
        $this->assertSame($user->tenant_id, (int) $payment->tenant_id);
        $this->assertSame($user->id, (int) $payment->recorded_by);
        $this->assertEquals(500.00, (float) $payment->amount);
        $this->assertSame('cash', $payment->method);
        $this->assertSame('2025-02-15 00:00:00', $payment->paid_at);
        $this->assertStringContainsString('701', (string) $payment->reference);

        $mapping = QuickBooksImportMapping::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('quickbooks_connection_id', $connection->id)
            ->where('entity_type', 'payments')
            ->where('quickbooks_id', '701')
            ->firstOrFail();
        $this->assertSame('App\\Models\\InvoicePayment', $mapping->local_type);
        $metadata = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata, true);
        $this->assertSame(0.0, (float) $metadata['source_unapplied_amount']);
        $this->assertTrue($metadata['invoice_aggregate_already_imported']);

        if (Schema::hasColumn('invoices', 'amount_paid')) {
            $this->assertEquals((float) $paidBefore, (float) DB::table('invoices')->where('id', $invoice->id)->value('amount_paid'));
        }
        if (Schema::hasColumn('invoices', 'balance_due')) {
            $this->assertEquals((float) $balanceBefore, (float) DB::table('invoices')->where('id', $invoice->id)->value('balance_due'));
        }
    }

    public function test_import_is_idempotent_and_preserves_local_payment_edits(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $customer = $this->customerFor($user);
        $invoice = $this->invoiceFor($user, $customer, 'INV-1001');
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);
        $this->createMapping($user, $connection, 'invoices', '601', Invoice::class, $invoice->id);
        $this->fakePayments($this->paymentRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()->assertJsonPath('data.imported_count', 1);

        $payment = DB::table('invoice_payments')->where('tenant_id', $user->tenant_id)->firstOrFail();
        DB::table('invoice_payments')->where('id', $payment->id)->update(['notes' => 'Edited inside Bizflow']);

        $this->fakePayments($this->paymentRecords());
        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.already_imported_count', 1)
            ->assertJsonPath('data.allocation_count', 0)
            ->assertJsonPath('data.skipped_count', 0);

        $this->assertSame(1, DB::table('invoice_payments')->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame('Edited inside Bizflow', DB::table('invoice_payments')->where('id', $payment->id)->value('notes'));
    }

    public function test_one_quickbooks_payment_can_allocate_to_multiple_invoices(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $customer = $this->customerFor($user);
        $invoiceOne = $this->invoiceFor($user, $customer, 'INV-1001');
        $invoiceTwo = $this->invoiceFor($user, $customer, 'INV-1002');
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);
        $this->createMapping($user, $connection, 'invoices', '601', Invoice::class, $invoiceOne->id);
        $this->createMapping($user, $connection, 'invoices', '602', Invoice::class, $invoiceTwo->id);

        $payment = $this->paymentRecords()[0];
        $payment['Line'] = [
            ['Amount' => 175, 'LinkedTxn' => [['TxnId' => '601', 'TxnType' => 'Invoice']]],
            ['Amount' => 325, 'LinkedTxn' => [['TxnId' => '602', 'TxnType' => 'Invoice']]],
        ];
        $this->fakePayments([$payment]);

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.allocation_count', 2)
            ->assertJsonPath('data.skipped_count', 0);

        $rows = DB::table('invoice_payments')->where('tenant_id', $user->tenant_id)->orderBy('amount')->get();
        $this->assertCount(2, $rows);
        $this->assertEquals([175.0, 325.0], $rows->map(fn ($row) => (float) $row->amount)->all());
        $this->assertCount(1, QuickBooksImportMapping::query()->where('tenant_id', $user->tenant_id)->where('entity_type', 'payments')->where('quickbooks_id', '701')->get());
    }

    public function test_payment_with_unmapped_invoice_is_skipped_safely(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $customer = $this->customerFor($user);
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);
        $this->fakePayments($this->paymentRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.unresolved_invoice_reference_count', 1);

        $this->assertSame(0, DB::table('invoice_payments')->where('tenant_id', $user->tenant_id)->count());
    }

    public function test_payment_with_unmapped_customer_is_skipped(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakePayments($this->paymentRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.skipped_count', 1);

        $this->assertDatabaseCount('invoice_payments', 0);
    }

    public function test_unapplied_payment_without_invoice_link_is_skipped_and_reported(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $customer = $this->customerFor($user);
        $this->createMapping($user, $connection, 'customers', '101', Customer::class, $customer->id);
        $record = $this->paymentRecords()[0];
        $record['UnappliedAmt'] = 500;
        $record['Line'] = [];
        $this->fakePayments([$record]);

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.unapplied_or_unlinked_payment_count', 1);

        $this->assertDatabaseCount('invoice_payments', 0);
    }

    public function test_invalid_provider_payment_list_returns_safe_error_without_importing(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakePayments(['Payment' => 'not a payment list']);

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertStatus(502)
            ->assertJsonPath('success', false);
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseCount('quickbooks_import_mappings', 0);
    }

    public function test_user_without_migration_import_permission_cannot_import_payments(): void
    {
        $user = $this->userForTenant();
        Permission::findOrCreate('migration.import', 'web');
        $connection = $this->connectionFor($user);

        $this->actingAs($user)->postJson($this->endpoint($connection))->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('invoice_payments', 0);
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
        $role = Role::findOrCreate('quickbooks-payments-import-test-role', 'web');
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
        return '/api/integrations/quickbooks/connections/'.$connection->id.'/migration-catalog/payments/import';
    }

    private function paymentRecords(): array
    {
        return [[
            'Id' => '701',
            'CustomerRef' => ['value' => '101', 'name' => 'Ada Mensah'],
            'TxnDate' => '2025-02-15',
            'TotalAmt' => 500.00,
            'UnappliedAmt' => 0.00,
            'PaymentMethodRef' => ['value' => '1', 'name' => 'Cash'],
            'CheckNum' => 'CH-701',
            'PrivateNote' => 'Historical customer payment',
            'Line' => [[
                'Amount' => 500.00,
                'LinkedTxn' => [['TxnId' => '601', 'TxnType' => 'Invoice']],
            ]],
        ]];
    }

    private function fakePayments(array $rows,array $paymentMethods = []): void 
    {
        $queryResponse = array_is_list($rows)
            ? ['Payment' => $rows]
            : $rows;

        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/v3/company/*/query*'
                => function ($request) use (
                    $queryResponse,
                    $paymentMethods
                ) {
                    // Read query parameters from the request URL.
                    $urlQuery = parse_url(
                        $request->url(),
                        PHP_URL_QUERY
                    );

                    parse_str(
                        is_string($urlQuery) ? $urlQuery : '',
                        $queryParams
                    );

                    $query = strtolower(
                        (string) ($queryParams['query'] ?? '')
                    );

                    $responseData = str_contains(
                        $query,
                        'paymentmethod'
                    )
                        ? ['PaymentMethod' => $paymentMethods]
                        : $queryResponse;

                    return Http::response([
                        'QueryResponse' => $responseData,
                    ], 200);
                },
        ]);
    }

     public function test_import_resolves_missing_payment_method_name(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);

        $connection = $this->connectionFor($user);
        $customer = $this->customerFor($user);
        $invoice = $this->invoiceFor($user, $customer, 'INV-1001');

        $this->createMapping(
            $user,
            $connection,
            'customers',
            '101',
            Customer::class,
            $customer->id
        );

        $this->createMapping(
            $user,
            $connection,
            'invoices',
            '601',
            Invoice::class,
            $invoice->id
        );

        $record = $this->paymentRecords()[0];

        // Preserve the method ID but simulate a response without its name.
        $record['PaymentMethodRef'] = ['value' => '1'];

        $this->fakePayments(
            [$record],
            [
                ['Id' => '1', 'Name' => 'Cash'],
            ]
        );

        $this->actingAs($user)
            ->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 1);

        $payment = DB::table('invoice_payments')
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $this->assertSame('cash', $payment->method);

        $mapping = QuickBooksImportMapping::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('quickbooks_connection_id', $connection->id)
            ->where('entity_type', 'payments')
            ->where('quickbooks_id', '701')
            ->firstOrFail();

        $metadata = is_array($mapping->metadata)
            ? $mapping->metadata
            : json_decode($mapping->metadata, true);

        $this->assertSame(
            'Cash',
            $metadata['source_payment_method_name']
        );
    }
}
