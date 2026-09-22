<?php

namespace Tests\Feature;

use App\PurchasePaymentMethod;
use App\PurchasePaymentStatus;
use App\Models\Branch;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_can_have_a_payment(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $supplier = Supplier::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'total' => 1000,
            ]);

        $payment = PurchasePayment::create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 400,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'PAY-001',
            'paid_at' => now(),
        ]);

        $this->assertTrue(
            $purchase->payments->contains($payment)
        );
    }

    public function test_payment_belongs_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $supplier = Supplier::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
            ]);

        $payment = PurchasePayment::create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 100,
            'method' => PurchasePaymentMethod::CASH,
            'paid_at' => now(),
        ]);

        $this->assertEquals(
            $tenant->id,
            $payment->tenant->id
        );
    }

    public function test_payment_belongs_to_recording_user(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $supplier = Supplier::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
            ]);

        $payment = PurchasePayment::create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 100,
            'method' => PurchasePaymentMethod::BANK_TRANSFER,
            'paid_at' => now(),
        ]);

        $this->assertEquals(
            $user->id,
            $payment->recordedBy->id
        );
    }

    public function test_payment_amount_and_method_are_cast_correctly(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $supplier = Supplier::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
            ]);

        $payment = PurchasePayment::create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 250.50,
            'method' => PurchasePaymentMethod::MOBILE_MONEY,
            'paid_at' => now(),
        ]);

        $payment->refresh();

        $this->assertEquals('250.50', $payment->amount);

        $this->assertEquals(
            PurchasePaymentMethod::MOBILE_MONEY,
            $payment->method
        );
    }

    public function test_payment_reference_must_be_unique_within_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $supplier = Supplier::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
            ]);

        PurchasePayment::create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 100,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'PAY-001',
            'paid_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PurchasePayment::create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 200,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'PAY-001',
            'paid_at' => now(),
        ]);
    }

    public function test_different_tenants_can_use_same_payment_reference(): void
    {
        $tenantOne = Tenant::factory()->create();
        $tenantTwo = Tenant::factory()->create();

        $branchOne = Branch::factory()->for($tenantOne)->create();
        $branchTwo = Branch::factory()->for($tenantTwo)->create();

        $supplierOne = Supplier::factory()->for($tenantOne)->create();
        $supplierTwo = Supplier::factory()->for($tenantTwo)->create();

        $userOne = User::factory()->for($tenantOne)->create();
        $userTwo = User::factory()->for($tenantTwo)->create();

        $purchaseOne = Purchase::factory()
            ->for($tenantOne)
            ->create([
                'branch_id' => $branchOne->id,
                'supplier_id' => $supplierOne->id,
                'created_by' => $userOne->id,
            ]);

        $purchaseTwo = Purchase::factory()
            ->for($tenantTwo)
            ->create([
                'branch_id' => $branchTwo->id,
                'supplier_id' => $supplierTwo->id,
                'created_by' => $userTwo->id,
            ]);

        PurchasePayment::create([
            'tenant_id' => $tenantOne->id,
            'purchase_id' => $purchaseOne->id,
            'recorded_by' => $userOne->id,
            'amount' => 100,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'PAY-001',
            'paid_at' => now(),
        ]);

        $payment = PurchasePayment::create([
            'tenant_id' => $tenantTwo->id,
            'purchase_id' => $purchaseTwo->id,
            'recorded_by' => $userTwo->id,
            'amount' => 200,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'PAY-001',
            'paid_at' => now(),
        ]);

        $this->assertDatabaseHas('purchase_payments', [
            'id' => $payment->id,
            'tenant_id' => $tenantTwo->id,
            'reference' => 'PAY-001',
        ]);
    }

    public function test_payment_cannot_exceed_purchase_outstanding_balance(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $supplier = Supplier::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'total' => 10000,
            ]);

        PurchasePayment::create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 4000,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'PAY-001',
            'paid_at' => now(),
        ]);

        $service = app(\App\Services\PurchasePaymentService::class);

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 7000,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'PAY-002',
            'paid_at' => now(),
        ]);
    }

    public function test_partial_payment_changes_purchase_to_partially_paid(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $supplier = Supplier::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'total' => 10000,
                'payment_status' => \App\PurchasePaymentStatus::UNPAID,
            ]);

        $service = app(\App\Services\PurchasePaymentService::class);

        $payment = $service->create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 4000,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'PAY-PARTIAL-001',
            'paid_at' => now(),
        ]);

        $purchase->refresh();

        $this->assertEquals('4000.00', $payment->amount);

        $this->assertEquals(
            \App\PurchasePaymentStatus::PARTIALLY_PAID,
            $purchase->payment_status
        );
    }

    public function test_final_payment_changes_purchase_to_paid(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()
            ->for($tenant)
            ->create();

        $supplier = Supplier::factory()
            ->for($tenant)
            ->create();

        $user = User::factory()
            ->for($tenant)
            ->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'total' => 10000,
                'payment_status' => \App\PurchasePaymentStatus::UNPAID,
            ]);

        $service = app(\App\Services\PurchasePaymentService::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 4000,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'PAY-001',
            'paid_at' => now(),
        ]);

        $service->create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 6000,
            'method' => PurchasePaymentMethod::BANK_TRANSFER,
            'reference' => 'PAY-002',
            'paid_at' => now(),
        ]);

        $purchase->refresh();

        $this->assertEquals(
            \App\PurchasePaymentStatus::PAID,
            $purchase->payment_status
        );

        $this->assertEquals(
            10000,
            (float) $purchase->payments()->sum('amount')
        );
    }

    public function test_zero_payment_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();
        $supplier = Supplier::factory()->for($tenant)->create();
        $user = User::factory()->for($tenant)->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'total' => 10000,
            ]);

        $service = app(\App\Services\PurchasePaymentService::class);

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 0,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'PAY-ZERO-001',
            'paid_at' => now(),
        ]);
    }

    public function test_negative_payment_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();
        $supplier = Supplier::factory()->for($tenant)->create();
        $user = User::factory()->for($tenant)->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'total' => 10000,
            ]);

        $service = app(\App\Services\PurchasePaymentService::class);

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => -100,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'PAY-NEGATIVE-001',
            'paid_at' => now(),
        ]);
    }

    public function test_payment_cannot_be_recorded_against_purchase_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->for($tenantA)->create();
        $supplierA = Supplier::factory()->for($tenantA)->create();
        $userB = User::factory()->for($tenantB)->create();

        $purchaseA = Purchase::factory()
            ->for($tenantA)
            ->create([
                'branch_id' => $branchA->id,
                'supplier_id' => $supplierA->id,
                'created_by' => User::factory()->for($tenantA)->create()->id,
                'total' => 10000,
            ]);

        $service = app(\App\Services\PurchasePaymentService::class);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $service->create([
            'tenant_id' => $tenantB->id,
            'purchase_id' => $purchaseA->id,
            'recorded_by' => $userB->id,
            'amount' => 1000,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'TENANT-B-PAY-001',
            'paid_at' => now(),
        ]);
    }

    public function test_payment_cannot_use_recording_user_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->for($tenantA)->create();
        $supplierA = Supplier::factory()->for($tenantA)->create();

        $userA = User::factory()->for($tenantA)->create();
        $userB = User::factory()->for($tenantB)->create();

        $purchaseA = Purchase::factory()
            ->for($tenantA)
            ->create([
                'branch_id' => $branchA->id,
                'supplier_id' => $supplierA->id,
                'created_by' => $userA->id,
                'total' => 10000,
            ]);

        $service = app(\App\Services\PurchasePaymentService::class);

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->create([
            'tenant_id' => $tenantA->id,
            'purchase_id' => $purchaseA->id,
            'recorded_by' => $userB->id,
            'amount' => 1000,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'CROSS-TENANT-USER-001',
            'paid_at' => now(),
        ]);
    }

    public function test_duplicate_payment_reference_is_rejected_by_service(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();
        $supplier = Supplier::factory()->for($tenant)->create();
        $user = User::factory()->for($tenant)->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'total' => 10000,
            ]);

        $service = app(\App\Services\PurchasePaymentService::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 1000,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'DUPLICATE-001',
            'paid_at' => now(),
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 500,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'DUPLICATE-001',
            'paid_at' => now(),
        ]);
    }

    public function test_failed_duplicate_payment_does_not_change_payment_status_or_total_paid(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();
        $supplier = Supplier::factory()->for($tenant)->create();
        $user = User::factory()->for($tenant)->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'total' => 10000,
                'payment_status' => \App\PurchasePaymentStatus::UNPAID,
            ]);

        $service = app(\App\Services\PurchasePaymentService::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 4000,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'ROLLBACK-001',
            'paid_at' => now(),
        ]);

        $this->assertEquals(
            4000,
            (float) $purchase->payments()->sum('amount')
        );

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        try {
            $service->create([
                'tenant_id' => $tenant->id,
                'purchase_id' => $purchase->id,
                'recorded_by' => $user->id,
                'amount' => 2000,
                'method' => PurchasePaymentMethod::BANK_TRANSFER,
                'reference' => 'ROLLBACK-001',
                'paid_at' => now(),
            ]);
        } finally {
            $purchase->refresh();

            $this->assertEquals(
                \App\PurchasePaymentStatus::PARTIALLY_PAID,
                $purchase->payment_status
            );

            $this->assertEquals(
                4000,
                (float) $purchase->payments()->sum('amount')
            );
        }
    }

    public function test_payment_cannot_be_added_to_fully_paid_purchase(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();
        $supplier = Supplier::factory()->for($tenant)->create();
        $user = User::factory()->for($tenant)->create();

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'total' => 10000,
                'payment_status' => \App\PurchasePaymentStatus::PAID,
            ]);

        PurchasePayment::create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 10000,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'FULL-001',
            'paid_at' => now(),
        ]);

        $service = app(\App\Services\PurchasePaymentService::class);

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'recorded_by' => $user->id,
            'amount' => 1,
            'method' => PurchasePaymentMethod::CASH,
            'reference' => 'AFTER-PAID-001',
            'paid_at' => now(),
        ]);
    }
}