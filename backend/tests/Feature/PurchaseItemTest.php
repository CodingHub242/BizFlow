<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_can_contain_catalog_item(): void
    {
        $tenant = Tenant::factory()->create();

        $purchase = Purchase::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $item = CatalogItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $purchaseItem = PurchaseItem::factory()->create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
            'catalog_item_id' => $item->id,
            'quantity' => 10,
            'unit_price' => 85,
            'line_total' => 850,
        ]);

        $this->assertTrue(
            $purchaseItem->purchase->is($purchase)
        );

        $this->assertTrue(
            $purchaseItem->catalogItem->is($item)
        );
    }

    public function test_purchase_can_contain_multiple_items(): void
    {
        $tenant = Tenant::factory()->create();

        $purchase = Purchase::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        PurchaseItem::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'purchase_id' => $purchase->id,
        ]);

        $this->assertCount(
            3,
            $purchase->items
        );
    }

    public function test_purchase_item_stores_purchase_price(): void
    {
        $purchaseItem = PurchaseItem::factory()->create([
            'unit_price' => 85.50,
            'quantity' => 10,
            'line_total' => 855,
        ]);

        $this->assertEquals(
            '85.50',
            $purchaseItem->unit_price
        );

        $this->assertEquals(
            '10.000',
            $purchaseItem->quantity
        );

        $this->assertEquals(
            '855.00',
            $purchaseItem->line_total
        );
    }

    public function test_purchase_item_belongs_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $purchaseItem = PurchaseItem::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assertTrue(
            $purchaseItem->tenant->is($tenant)
        );
    }

    public function test_purchase_items_are_isolated_between_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $purchaseA = Purchase::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $purchaseB = Purchase::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        PurchaseItem::factory()->create([
            'tenant_id' => $tenantA->id,
            'purchase_id' => $purchaseA->id,
        ]);

        PurchaseItem::factory()->create([
            'tenant_id' => $tenantB->id,
            'purchase_id' => $purchaseB->id,
        ]);

        $this->assertCount(1, $purchaseA->items);
        $this->assertCount(1, $purchaseB->items);
    }

    public function test_purchase_item_quantity_and_prices_are_cast_correctly(): void
    {
        $purchaseItem = PurchaseItem::factory()->create([
            'quantity' => 12.500,
            'unit_price' => 100.25,
            'discount' => 10.50,
            'tax' => 15.00,
            'line_total' => 1260.75,
        ]);

        $this->assertEquals('12.500', $purchaseItem->quantity);
        $this->assertEquals('100.25', $purchaseItem->unit_price);
        $this->assertEquals('10.50', $purchaseItem->discount);
        $this->assertEquals('15.00', $purchaseItem->tax);
        $this->assertEquals('1260.75', $purchaseItem->line_total);
    }
}