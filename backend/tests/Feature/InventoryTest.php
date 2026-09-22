<?php

namespace Tests\Feature;

use App\CatalogItemType;
use App\InventoryMovementType;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_create_a_branch(): void
    {
        $tenant = Tenant::create([
            'name' => 'ABC Business',
            'slug' => 'abc-business',
        ]);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Accra Branch',
            'code' => 'ACC',
            'address' => 'Accra',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'tenant_id' => $tenant->id,
            'name' => 'Accra Branch',
            'code' => 'ACC',
        ]);

        $this->assertTrue($branch->tenant->is($tenant));
    }

    public function test_product_can_have_different_stock_at_different_branches(): void
    {
        $tenant = Tenant::create([
            'name' => 'ABC Business',
            'slug' => 'abc-business',
        ]);

        $accra = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Accra Branch',
            'code' => 'ACC',
        ]);

        $kumasi = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Kumasi Branch',
            'code' => 'KUM',
        ]);

        $product = CatalogItem::create([
            'tenant_id' => $tenant->id,
            'type' => CatalogItemType::PRODUCT,
            'name' => 'Office Chair',
            'sku' => 'CHAIR-001',
            'selling_price' => 1200.00,
            'track_inventory' => true,
        ]);

        $accraInventory = Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $accra->id,
            'catalog_item_id' => $product->id,
            'quantity' => 20,
            'reorder_level' => 5,
        ]);

        $kumasiInventory = Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $kumasi->id,
            'catalog_item_id' => $product->id,
            'quantity' => 8,
            'reorder_level' => 3,
        ]);

        $this->assertSame('20.000', $accraInventory->quantity);
        $this->assertSame('8.000', $kumasiInventory->quantity);

        $this->assertTrue($accraInventory->branch->is($accra));
        $this->assertTrue($kumasiInventory->branch->is($kumasi));

        $this->assertTrue($accraInventory->catalogItem->is($product));
        $this->assertTrue($kumasiInventory->catalogItem->is($product));
    }

    public function test_inventory_is_isolated_between_tenants(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Business A',
            'slug' => 'business-a',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Business B',
            'slug' => 'business-b',
        ]);

        $branchA = Branch::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Business A Branch',
            'code' => 'A-ACC',
        ]);

        $branchB = Branch::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Business B Branch',
            'code' => 'B-ACC',
        ]);

        $productA = CatalogItem::create([
            'tenant_id' => $tenantA->id,
            'type' => CatalogItemType::PRODUCT,
            'name' => 'Product A',
            'sku' => 'PROD-A',
            'selling_price' => 100,
            'track_inventory' => true,
        ]);

        $productB = CatalogItem::create([
            'tenant_id' => $tenantB->id,
            'type' => CatalogItemType::PRODUCT,
            'name' => 'Product B',
            'sku' => 'PROD-B',
            'selling_price' => 200,
            'track_inventory' => true,
        ]);

        $inventoryA = Inventory::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'catalog_item_id' => $productA->id,
            'quantity' => 50,
        ]);

        $inventoryB = Inventory::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'catalog_item_id' => $productB->id,
            'quantity' => 100,
        ]);

        $tenantAInventory = $tenantA->inventories()->get();

        $this->assertTrue($tenantAInventory->contains($inventoryA));
        $this->assertFalse($tenantAInventory->contains($inventoryB));
    }

    public function test_inventory_movement_belongs_to_correct_branch_product_and_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'ABC Business',
            'slug' => 'abc-business',
        ]);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
        ]);

        $product = CatalogItem::create([
            'tenant_id' => $tenant->id,
            'type' => CatalogItemType::PRODUCT,
            'name' => 'Office Chair',
            'sku' => 'CHAIR-001',
            'selling_price' => 1200,
            'track_inventory' => true,
        ]);

        $movement = InventoryMovement::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'type' => InventoryMovementType::OPENING,
            'quantity' => 25,
            'notes' => 'Opening stock',
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'id' => $movement->id,
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'type' => 'opening',
            'quantity' => 25,
        ]);

        $this->assertTrue($movement->tenant->is($tenant));
        $this->assertTrue($movement->branch->is($branch));
        $this->assertTrue($movement->catalogItem->is($product));
    }

    public function test_service_does_not_require_inventory_tracking(): void
    {
        $tenant = Tenant::create([
            'name' => 'Interior Design Business',
            'slug' => 'interior-design-business',
        ]);

        $service = CatalogItem::create([
            'tenant_id' => $tenant->id,
            'type' => CatalogItemType::SERVICE,
            'name' => 'Interior Design Consultation',
            'sku' => 'SVC-001',
            'selling_price' => 500,
            'track_inventory' => false,
        ]);

        $this->assertSame(
            CatalogItemType::SERVICE,
            $service->type
        );

        $this->assertFalse($service->track_inventory);

        $this->assertCount(0, $service->inventories);
    }
}