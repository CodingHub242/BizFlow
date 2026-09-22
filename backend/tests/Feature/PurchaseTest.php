<?php

namespace Tests\Feature;

use App\PurchasePaymentStatus;
use App\PurchaseStatus;
use App\Models\Branch;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_create_purchase(): void
    {
        $tenant = Tenant::factory()->create();

        $purchase = Purchase::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assertDatabaseHas('purchases', [
            'id' => $purchase->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_purchase_belongs_to_correct_supplier(): void
    {
        $tenant = Tenant::factory()->create();

        $supplier = Supplier::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $purchase = Purchase::factory()->create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
        ]);

        $this->assertTrue(
            $purchase->supplier->is($supplier)
        );
    }

    public function test_purchase_belongs_to_correct_branch(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $purchase = Purchase::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);

        $this->assertTrue(
            $purchase->branch->is($branch)
        );
    }

    public function test_purchase_belongs_to_creator(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $purchase = Purchase::factory()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
        ]);

        $this->assertTrue(
            $purchase->creator->is($user)
        );
    }

    public function test_purchase_status_is_cast_to_enum(): void
    {
        $purchase = Purchase::factory()->create([
            'status' => PurchaseStatus::ORDERED,
        ]);

        $this->assertSame(
            PurchaseStatus::ORDERED,
            $purchase->status
        );
    }

    public function test_purchase_payment_status_is_cast_to_enum(): void
    {
        $purchase = Purchase::factory()->create([
            'payment_status' => PurchasePaymentStatus::PAID,
        ]);

        $this->assertSame(
            PurchasePaymentStatus::PAID,
            $purchase->payment_status
        );
    }

    public function test_purchase_number_is_unique_within_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        Purchase::factory()->create([
            'tenant_id' => $tenant->id,
            'purchase_number' => 'PUR-000001',
        ]);

        $this->expectException(
            \Illuminate\Database\QueryException::class
        );

        Purchase::factory()->create([
            'tenant_id' => $tenant->id,
            'purchase_number' => 'PUR-000001',
        ]);
    }

    public function test_different_tenants_can_use_same_purchase_number(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Purchase::factory()->create([
            'tenant_id' => $tenantA->id,
            'purchase_number' => 'PUR-000001',
        ]);

        $purchase = Purchase::factory()->create([
            'tenant_id' => $tenantB->id,
            'purchase_number' => 'PUR-000001',
        ]);

        $this->assertDatabaseHas('purchases', [
            'id' => $purchase->id,
            'tenant_id' => $tenantB->id,
            'purchase_number' => 'PUR-000001',
        ]);
    }

    public function test_purchase_relationships_are_tenant_aligned(): void
    {
        $tenantA = Tenant::factory()->create();

        $supplier = Supplier::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $purchase = Purchase::factory()->create([
            'tenant_id' => $tenantA->id,
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
        ]);

        $this->assertEquals(
            $tenantA->id,
            $purchase->supplier->tenant_id
        );

        $this->assertEquals(
            $tenantA->id,
            $purchase->branch->tenant_id
        );

        $this->assertEquals(
            $tenantA->id,
            $purchase->creator->tenant_id
        );
    }
}