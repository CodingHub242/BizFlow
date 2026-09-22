<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_service_can_create_order_for_customer(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $order = app(OrderService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-TEST-001',
        ]);

        $this->assertInstanceOf(Order::class, $order);

        $this->assertEquals(
            $customer->id,
            $order->customer_id
        );

        $this->assertEquals(
            $tenant->id,
            $order->tenant_id
        );
    }

    public function test_order_service_can_create_walk_in_order(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $order = app(OrderService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'customer_id' => null,
            'order_number' => 'ORD-WALKIN-001',
        ]);

        $this->assertNull($order->customer_id);
    }

    public function test_order_service_rejects_customer_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $customerB = Customer::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'created_by' => $userA->id,
            'customer_id' => $customerB->id,
            'order_number' => 'ORD-CROSS-TENANT-001',
        ]);
    }

    public function test_order_service_rejects_branch_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchB = Branch::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(OrderService::class)->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchB->id,
            'created_by' => $userA->id,
            'customer_id' => null,
            'order_number' => 'ORD-CROSS-BRANCH-001',
        ]);
    }
}