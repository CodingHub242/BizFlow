<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_can_be_created_for_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $supplier = Supplier::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'ABC Supplies',
        ]);

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'tenant_id' => $tenant->id,
            'name' => 'ABC Supplies',
        ]);
    }

    public function test_supplier_belongs_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $supplier = Supplier::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assertTrue(
            $supplier->tenant->is($tenant)
        );
    }

    public function test_tenant_has_suppliers(): void
    {
        $tenant = Tenant::factory()->create();

        Supplier::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assertCount(
            3,
            $tenant->suppliers
        );
    }

    public function test_supplier_can_be_inactive(): void
    {
        $supplier = Supplier::factory()
            ->inactive()
            ->create();

        $this->assertEquals(
            'inactive',
            $supplier->status
        );
    }

    public function test_suppliers_are_isolated_between_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Supplier::factory()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Supplier A',
        ]);

        Supplier::factory()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Supplier B',
        ]);

        $this->assertCount(
            1,
            $tenantA->suppliers
        );

        $this->assertEquals(
            'Supplier A',
            $tenantA->suppliers->first()->name
        );

        $this->assertCount(
            1,
            $tenantB->suppliers
        );

        $this->assertEquals(
            'Supplier B',
            $tenantB->suppliers->first()->name
        );
    }
}