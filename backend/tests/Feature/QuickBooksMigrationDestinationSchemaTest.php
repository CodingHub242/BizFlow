<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickBooksMigrationDestinationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_chart_of_accounts_support_missing_account_numbers_and_hierarchy(): void
    {
        $tenant = Tenant::factory()->create();

        $parent = ChartOfAccount::create([
            'tenant_id' => $tenant->id,
            'name' => 'Expenses',
            'account_type' => 'Expense',
            'detail_type' => 'OtherBusinessExpenses',
            'account_number' => null,
            'is_active' => true,
        ]);

        $child = ChartOfAccount::create([
            'tenant_id' => $tenant->id,
            'parent_id' => $parent->id,
            'name' => 'Accounting',
            'account_type' => 'Expense',
            'detail_type' => 'LegalProfessionalFees',
            'account_number' => null,
            'is_sub_account' => true,
            'is_active' => true,
        ]);

        $this->assertNull($child->account_number);
        $this->assertTrue($child->is_sub_account);
        $this->assertSame($parent->id, $child->parent->id);
        $this->assertSame(1, $parent->children()->count());
    }

    public function test_bills_allow_missing_and_repeated_document_numbers(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $branch = Branch::factory()->for($tenant)->create();
        $supplier = Supplier::factory()->for($tenant)->create();

        $base = [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'bill_date' => '2026-09-16',
            'total' => 205.00,
            'balance' => 205.00,
            'status' => 'open',
            'payment_status' => 'unpaid',
        ];

        $billWithoutNumber = Bill::create($base + ['bill_number' => null]);
        $billSameNumberA = Bill::create($base + ['bill_number' => 'VENDOR-001']);
        $billSameNumberB = Bill::create($base + ['bill_number' => 'VENDOR-001']);

        $this->assertNull($billWithoutNumber->bill_number);
        $this->assertSame($billSameNumberA->bill_number, $billSameNumberB->bill_number);
        $this->assertSame(3, Bill::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_estimates_allow_repeated_document_numbers(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $branch = Branch::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create();

        $base = [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'estimate_number' => '1001',
            'estimate_date' => '2026-09-13',
            'total' => 362.07,
            'status' => 'closed',
            'source_status' => 'Closed',
        ];

        $first = Estimate::create($base);
        $second = Estimate::create($base);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('1001', $first->estimate_number);
        $this->assertSame('Closed', $second->source_status);
        $this->assertSame(2, Estimate::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_quickbooks_import_mapping_is_unique_by_connection_entity_and_source_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $connectionA = QuickBooksConnection::create([
            'tenant_id' => $tenant->id,
            'connected_by' => $user->id,
            'realm_id' => 'realm-a',
            'connection_status' => 'connected',
        ]);

        $connectionB = QuickBooksConnection::create([
            'tenant_id' => $tenant->id,
            'connected_by' => $user->id,
            'realm_id' => 'realm-b',
            'connection_status' => 'connected',
        ]);

        $mapping = [
            'tenant_id' => $tenant->id,
            'entity_type' => 'estimates',
            'quickbooks_id' => '100',
            'local_type' => Estimate::class,
            'local_id' => 1,
            'imported_at' => now(),
        ];

        QuickBooksImportMapping::create($mapping + [
            'quickbooks_connection_id' => $connectionA->id,
        ]);

        // The same numeric QuickBooks ID is legal for a different company.
        QuickBooksImportMapping::create($mapping + [
            'quickbooks_connection_id' => $connectionB->id,
        ]);

        $this->assertSame(2, QuickBooksImportMapping::query()
            ->where('tenant_id', $tenant->id)
            ->where('entity_type', 'estimates')
            ->where('quickbooks_id', '100')
            ->count());

        $this->expectException(QueryException::class);
        QuickBooksImportMapping::create($mapping + [
            'quickbooks_connection_id' => $connectionA->id,
        ]);
    }
}
