<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierCatalogItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_can_supply_multiple_catalog_items(): void
    {
        $tenant = Tenant::factory()->create();

        $supplier = Supplier::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $items = CatalogItem::factory()
            ->count(3)
            ->create([
                'tenant_id' => $tenant->id,
            ]);

        foreach ($items as $item) {
            SupplierCatalogItem::factory()->create([
                'tenant_id' => $tenant->id,
                'supplier_id' => $supplier->id,
                'catalog_item_id' => $item->id,
            ]);
        }

        $this->assertCount(
            3,
            $supplier->catalogItems
        );
    }

    public function test_catalog_item_can_have_multiple_suppliers(): void
    {
        $tenant = Tenant::factory()->create();

        $item = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $suppliers = Supplier::factory()
            ->count(3)
            ->create([
                'tenant_id' => $tenant->id,
            ]);

        foreach ($suppliers as $supplier) {
            SupplierCatalogItem::factory()->create([
                'tenant_id' => $tenant->id,
                'supplier_id' => $supplier->id,
                'catalog_item_id' => $item->id,
            ]);
        }

        $this->assertCount(
            3,
            $item->suppliers
        );
    }

    public function test_supplier_catalog_item_belongs_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $relationship = SupplierCatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assertTrue(
            $relationship->tenant->is($tenant)
        );
    }

    public function test_supplier_purchase_information_is_stored(): void
    {
        $relationship = SupplierCatalogItem::factory()->create([
            'purchase_price' => 85.50,
            'minimum_order_quantity' => 10,
            'lead_time_days' => 5,
            'supplier_sku' => 'ABC-CEM-50',
        ]);

        $this->assertEquals(
            '85.50',
            $relationship->purchase_price
        );

        $this->assertEquals(
            '10.000',
            $relationship->minimum_order_quantity
        );

        $this->assertEquals(
            5,
            $relationship->lead_time_days
        );

        $this->assertEquals(
            'ABC-CEM-50',
            $relationship->supplier_sku
        );
    }

    public function test_supplier_catalog_item_can_be_preferred(): void
    {
        $relationship = SupplierCatalogItem::factory()
            ->preferred()
            ->create();

        $this->assertTrue(
            $relationship->is_preferred
        );
    }

    public function test_supplier_catalog_relationships_are_isolated_between_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $supplierA = Supplier::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $itemA = CatalogItem::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        SupplierCatalogItem::factory()->create([
            'tenant_id' => $tenantA->id,
            'supplier_id' => $supplierA->id,
            'catalog_item_id' => $itemA->id,
        ]);

        $supplierB = Supplier::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $itemB = CatalogItem::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        SupplierCatalogItem::factory()->create([
            'tenant_id' => $tenantB->id,
            'supplier_id' => $supplierB->id,
            'catalog_item_id' => $itemB->id,
        ]);

        $this->assertCount(1, $supplierA->catalogItems);
        $this->assertCount(1, $itemA->suppliers);

        $this->assertEquals(
            $supplierA->id,
            $supplierA->catalogItems->first()->supplier_id
        );

        $this->assertEquals(
            $itemA->id,
            $itemA->suppliers->first()->catalog_item_id
        );
    }

    public function test_same_supplier_cannot_be_attached_to_same_catalog_item_twice(): void
    {
        $tenant = Tenant::factory()->create();

        $supplier = Supplier::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $item = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        SupplierCatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'catalog_item_id' => $item->id,
        ]);

        $this->expectException(
            \Illuminate\Database\QueryException::class
        );

        SupplierCatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'catalog_item_id' => $item->id,
        ]);
    }
}