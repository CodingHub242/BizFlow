<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Tenant;
use App\Models\User;
use App\OrderPaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_can_have_a_payment(): void
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

        $payment = OrderPayment::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 2000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'PAY-001',
            'paid_at' => now(),
        ]);

        $this->assertDatabaseHas('order_payments', [
            'id' => $payment->id,
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'amount' => 2000,
        ]);
    }

    public function test_payment_belongs_to_order(): void
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

        $payment = OrderPayment::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 2000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'PAY-002',
            'paid_at' => now(),
        ]);

        $this->assertTrue($payment->order->is($order));
    }

    public function test_payment_belongs_to_tenant(): void
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

        $payment = OrderPayment::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 2000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'PAY-003',
            'paid_at' => now(),
        ]);

        $this->assertTrue($payment->tenant->is($tenant));
    }

    public function test_payment_belongs_to_recording_user(): void
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

        $payment = OrderPayment::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 2000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'PAY-004',
            'paid_at' => now(),
        ]);

        $this->assertTrue($payment->recordedBy->is($user));
    }

    public function test_payment_amount_and_method_are_cast_correctly(): void
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

        $payment = OrderPayment::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 2000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'PAY-005',
            'paid_at' => now(),
        ]);

        $payment->refresh();

        $this->assertSame('2000.00', $payment->amount);
        $this->assertInstanceOf(
            OrderPaymentMethod::class,
            $payment->method
        );
        $this->assertInstanceOf(
            \Illuminate\Support\Carbon::class,
            $payment->paid_at
        );
    }

    public function test_payment_reference_must_be_unique_within_tenant(): void
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
                'total' => 10000,
            ]);

        OrderPayment::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 2000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'PAY-UNIQUE-001',
            'paid_at' => now(),
        ]);

        $this->expectException(
            \Illuminate\Database\UniqueConstraintViolationException::class
        );

        OrderPayment::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 3000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'PAY-UNIQUE-001',
            'paid_at' => now(),
        ]);
    }

    public function test_different_tenants_can_use_same_payment_reference(): void
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

        $orderA = Order::factory()
            ->for($tenantA)
            ->create([
                'branch_id' => $branchA->id,
                'created_by' => $userA->id,
                'total' => 5000,
            ]);

        $orderB = Order::factory()
            ->for($tenantB)
            ->create([
                'branch_id' => $branchB->id,
                'created_by' => $userB->id,
                'total' => 5000,
            ]);

        OrderPayment::create([
            'tenant_id' => $tenantA->id,
            'order_id' => $orderA->id,
            'recorded_by' => $userA->id,
            'amount' => 2000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'PAY-SAME-001',
            'paid_at' => now(),
        ]);

        $payment = OrderPayment::create([
            'tenant_id' => $tenantB->id,
            'order_id' => $orderB->id,
            'recorded_by' => $userB->id,
            'amount' => 3000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'PAY-SAME-001',
            'paid_at' => now(),
        ]);

        $this->assertDatabaseHas('order_payments', [
            'id' => $payment->id,
            'tenant_id' => $tenantB->id,
            'reference' => 'PAY-SAME-001',
        ]);
    }

    public function test_payment_cannot_exceed_order_outstanding_balance(): void
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

        $service = app(\App\Services\OrderPaymentService::class);

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 5001,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'ORDER-OVERPAY-001',
            'paid_at' => now(),
        ]);
    }

    public function test_partial_payment_changes_order_to_partially_paid(): void
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
                'payment_status' => \App\PaymentStatus::UNPAID,
            ]);

        $service = app(\App\Services\OrderPaymentService::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 2000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'ORDER-PARTIAL-001',
            'paid_at' => now(),
        ]);

        $order->refresh();

        $this->assertEquals(
            \App\PaymentStatus::PARTIALLY_PAID,
            $order->payment_status
        );
    }

    public function test_final_payment_changes_order_to_paid(): void
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
                'payment_status' => \App\PaymentStatus::UNPAID,
            ]);

        $service = app(\App\Services\OrderPaymentService::class);

        // First payment
        $service->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 2000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'ORDER-FINAL-001',
            'paid_at' => now(),
        ]);

        // Final payment
        $service->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 3000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'ORDER-FINAL-002',
            'paid_at' => now(),
        ]);

        $order->refresh();

        $this->assertEquals(
            \App\PaymentStatus::PAID,
            $order->payment_status
        );
    }

    public function test_zero_payment_is_rejected(): void
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

        $service = app(\App\Services\OrderPaymentService::class);

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 0,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'ORDER-ZERO-001',
            'paid_at' => now(),
        ]);
    }

    public function test_negative_payment_is_rejected(): void
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

        $service = app(\App\Services\OrderPaymentService::class);

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => -100,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'ORDER-NEGATIVE-001',
            'paid_at' => now(),
        ]);
    }

    public function test_payment_cannot_be_recorded_against_order_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchB = Branch::factory()
            ->for($tenantB)
            ->create();

        $userA = User::factory()
            ->for($tenantA)
            ->create();

        $userB = User::factory()
            ->for($tenantB)
            ->create();

        $orderB = Order::factory()
            ->for($tenantB)
            ->create([
                'branch_id' => $branchB->id,
                'created_by' => $userB->id,
                'total' => 5000,
            ]);

        $service = app(\App\Services\OrderPaymentService::class);

        $this->expectException(
            \Illuminate\Database\Eloquent\ModelNotFoundException::class
        );

        $service->create([
            'tenant_id' => $tenantA->id,
            'order_id' => $orderB->id,
            'recorded_by' => $userA->id,
            'amount' => 1000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'CROSS-TENANT-001',
            'paid_at' => now(),
        ]);
    }

    public function test_payment_cannot_use_recording_user_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()
            ->for($tenantA)
            ->create();

        $userA = User::factory()
            ->for($tenantA)
            ->create();

        $userB = User::factory()
            ->for($tenantB)
            ->create();

        $orderA = Order::factory()
            ->for($tenantA)
            ->create([
                'branch_id' => $branchA->id,
                'created_by' => $userA->id,
                'total' => 5000,
            ]);

        $service = app(\App\Services\OrderPaymentService::class);

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->create([
            'tenant_id' => $tenantA->id,
            'order_id' => $orderA->id,
            'recorded_by' => $userB->id,
            'amount' => 1000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'CROSS-TENANT-USER-001',
            'paid_at' => now(),
        ]);
    }

    public function test_duplicate_payment_reference_is_rejected_by_service(): void
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
                'total' => 10000,
            ]);

        $service = app(\App\Services\OrderPaymentService::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 2000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'DUPLICATE-ORDER-001',
            'paid_at' => now(),
        ]);

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 3000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'DUPLICATE-ORDER-001',
            'paid_at' => now(),
        ]);
    }

    public function test_failed_duplicate_payment_does_not_change_payment_status_or_total_paid(): void
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
                'total' => 10000,
            ]);

        $service = app(\App\Services\OrderPaymentService::class);

        $service->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'amount' => 2000,
            'method' => OrderPaymentMethod::CASH,
            'reference' => 'ROLLBACK-ORDER-001',
            'paid_at' => now(),
        ]);

        $this->assertSame(1, $order->payments()->count());

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        try {
            $service->create([
                'tenant_id' => $tenant->id,
                'order_id' => $order->id,
                'recorded_by' => $user->id,
                'amount' => 3000,
                'method' => OrderPaymentMethod::CASH,
                'reference' => 'ROLLBACK-ORDER-001',
                'paid_at' => now(),
            ]);
        } finally {
            $order->refresh();

            $this->assertSame(
                \App\PaymentStatus::PARTIALLY_PAID,
                $order->payment_status
            );

            $this->assertEquals(
                2000,
                (float) $order->payments()->sum('amount')
            );

            $this->assertSame(
                1,
                $order->payments()->count()
            );
        }
    }
}