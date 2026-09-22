<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_create_an_invoice(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-000001',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'discount' => 0,
            'tax' => 0,
            'total' => 5000,
            'issued_at' => now(),
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'tenant_id' => $tenant->id,
            'invoice_number' => 'INV-000001',
            'total' => 5000,
        ]);
    }

    public function test_invoice_belongs_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-000002',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'discount' => 0,
            'tax' => 0,
            'total' => 5000,
            'issued_at' => now(),
        ]);

        $this->assertTrue($invoice->tenant->is($tenant));
    }

    public function test_invoice_belongs_to_branch(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-000003',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'discount' => 0,
            'tax' => 0,
            'total' => 5000,
            'issued_at' => now(),
        ]);

        $this->assertTrue($invoice->branch->is($branch));
    }

    public function test_invoice_belongs_to_creator(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-000004',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'discount' => 0,
            'tax' => 0,
            'total' => 5000,
            'issued_at' => now(),
        ]);

        $this->assertTrue($invoice->createdBy->is($user));
    }

    public function test_invoice_belongs_to_customer(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $customer = Customer::factory()
            ->for($tenant)
            ->create();

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-000005',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'discount' => 0,
            'tax' => 0,
            'total' => 5000,
            'issued_at' => now(),
        ]);

        $this->assertTrue($invoice->customer->is($customer));
    }

    public function test_invoice_belongs_to_order(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $order = Order::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'created_by' => $user->id,
                'total' => 5000,
            ]);

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-000006',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'discount' => 0,
            'tax' => 0,
            'total' => 5000,
            'issued_at' => now(),
        ]);

        $this->assertTrue($invoice->order->is($order));
    }

    public function test_invoice_can_exist_without_customer(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => null,
            'created_by' => $user->id,
            'invoice_number' => 'INV-000007',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 3000,
            'discount' => 0,
            'tax' => 0,
            'total' => 3000,
            'issued_at' => now(),
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'customer_id' => null,
        ]);

        $this->assertNull($invoice->customer);
    }

    public function test_invoice_number_must_be_unique_within_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        Invoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-UNIQUE-001',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'discount' => 0,
            'tax' => 0,
            'total' => 5000,
            'issued_at' => now(),
        ]);

        $this->expectException(
            \Illuminate\Database\UniqueConstraintViolationException::class
        );

        Invoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-UNIQUE-001',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 3000,
            'discount' => 0,
            'tax' => 0,
            'total' => 3000,
            'issued_at' => now(),
        ]);
    }

    public function test_different_tenants_can_use_same_invoice_number(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()
            ->for($tenantA)
            ->create();

        $branchB = Branch::factory()
            ->for($tenantB)
            ->create();

        $userA = User::factory()
            ->for($tenantA)
            ->create();

        $userB = User::factory()
            ->for($tenantB)
            ->create();

        $invoiceA = Invoice::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userA->id,
            'invoice_number' => 'INV-SAME-001',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'discount' => 0,
            'tax' => 0,
            'total' => 5000,
            'issued_at' => now(),
        ]);

        $invoiceB = Invoice::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'created_by' => $userB->id,
            'invoice_number' => 'INV-SAME-001',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 3000,
            'discount' => 0,
            'tax' => 0,
            'total' => 3000,
            'issued_at' => now(),
        ]);

        $this->assertNotSame(
            $invoiceA->id,
            $invoiceB->id
        );

        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceA->id,
            'tenant_id' => $tenantA->id,
            'invoice_number' => 'INV-SAME-001',
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceB->id,
            'tenant_id' => $tenantB->id,
            'invoice_number' => 'INV-SAME-001',
        ]);
    }

    public function test_invoice_status_and_payment_status_are_cast_to_enums(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-ENUM-001',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'discount' => 0,
            'tax' => 0,
            'total' => 5000,
            'issued_at' => now(),
        ]);

        $invoice->refresh();

        $this->assertInstanceOf(
            \App\InvoiceStatus::class,
            $invoice->status
        );

        $this->assertInstanceOf(
            \App\PaymentStatus::class,
            $invoice->payment_status
        );
    }

    public function test_invoice_cannot_reference_records_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->for($tenantA)->create();
        $branchB = Branch::factory()->for($tenantB)->create();

        $userA = User::factory()->for($tenantA)->create();
        $userB = User::factory()->for($tenantB)->create();

        $customerA = Customer::factory()->for($tenantA)->create();
        $customerB = Customer::factory()->for($tenantB)->create();

        $orderA = Order::factory()
            ->for($tenantA)
            ->for($branchA)
            ->for($userA, 'createdBy')
            ->create();

        $orderB = Order::factory()
            ->for($tenantB)
            ->for($branchB)
            ->for($userB, 'createdBy')
            ->create();

        $invoice = Invoice::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'customer_id' => $customerA->id,
            'order_id' => $orderA->id,
            'created_by' => $userA->id,
            'invoice_number' => 'INV-ISOLATION-001',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 1000,
            'discount' => 0,
            'tax' => 0,
            'total' => 1000,
        ]);

        $this->assertEquals($tenantA->id, $invoice->tenant_id);
        $this->assertEquals($branchA->id, $invoice->branch_id);
        $this->assertEquals($customerA->id, $invoice->customer_id);
        $this->assertEquals($orderA->id, $invoice->order_id);
        $this->assertEquals($userA->id, $invoice->created_by);

        $this->assertNotEquals($branchB->id, $invoice->branch_id);
        $this->assertNotEquals($customerB->id, $invoice->customer_id);
        $this->assertNotEquals($orderB->id, $invoice->order_id);
        $this->assertNotEquals($userB->id, $invoice->created_by);
    }
}