<?php

namespace Tests\Feature;

use App\InventoryMovementType;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Tenant;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryService = app(InventoryService::class);
    }

    public function test_availability_reports_sufficient_stock(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
            'track_inventory' => true,
        ]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 10,
            'reorder_level' => 2,
        ]);

        $result = $this->inventoryService->checkAvailability(
            $tenant->id,
            $branch->id,
            $product->id,
            5
        );

        $this->assertSame(10.0, $result['available_quantity']);
        $this->assertSame(5.0, $result['requested_quantity']);
        $this->assertSame(0, $result['shortfall_quantity']);
        $this->assertTrue($result['can_fulfill']);
        $this->assertTrue($result['tracks_inventory']);
    }

    public function test_availability_reports_stock_shortage(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
            'track_inventory' => true,
        ]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 5,
            'reorder_level' => 2,
        ]);

        $result = $this->inventoryService->checkAvailability(
            $tenant->id,
            $branch->id,
            $product->id,
            8
        );

        $this->assertSame(5.0, $result['available_quantity']);
        $this->assertSame(8.0, $result['requested_quantity']);
        $this->assertSame(3.0, $result['shortfall_quantity']);
        $this->assertFalse($result['can_fulfill']);
    }

    public function test_service_does_not_require_inventory(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $service = CatalogItem::factory()->service()->create([
            'tenant_id' => $tenant->id,
            'track_inventory' => false,
        ]);

        $result = $this->inventoryService->checkAvailability(
            $tenant->id,
            $branch->id,
            $service->id,
            3
        );

        $this->assertNull($result['available_quantity']);
        $this->assertSame(0, $result['shortfall_quantity']);
        $this->assertTrue($result['can_fulfill']);
        $this->assertFalse($result['tracks_inventory']);
    }

    public function test_receive_stock_creates_inventory_and_movement(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
            'track_inventory' => true,
        ]);

        $inventory = $this->inventoryService->receiveStock(
            $tenant->id,
            $branch->id,
            $product->id,
            25,
            'Initial stock'
        );

        $this->assertEquals(25.0, (float) $inventory->quantity);

        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'type' => InventoryMovementType::PURCHASE->value,
            'quantity' => 25,
            'notes' => 'Initial stock',
        ]);
    }

    public function test_receive_stock_adds_to_existing_inventory(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 10,
            'reorder_level' => 2,
        ]);

        $inventory = $this->inventoryService->receiveStock(
            $tenant->id,
            $branch->id,
            $product->id,
            15
        );

        $this->assertEquals(25.0, (float) $inventory->quantity);

        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_sell_stock_fulfills_request_when_stock_is_sufficient(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 10,
            'reorder_level' => 2,
        ]);

        $result = $this->inventoryService->sellStock(
            $tenant->id,
            $branch->id,
            $product->id,
            4
        );

        $this->assertSame(4.0, $result['requested_quantity']);
        $this->assertSame(4.0, $result['fulfilled_quantity']);
        $this->assertSame(0.0, $result['shortfall_quantity']);

        $this->assertDatabaseHas('inventories', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 6,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'type' => InventoryMovementType::SALE->value,
            'quantity' => 4,
        ]);
    }

    public function test_sell_stock_partially_fulfills_when_stock_is_insufficient(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 5,
            'reorder_level' => 2,
        ]);

        $result = $this->inventoryService->sellStock(
            $tenant->id,
            $branch->id,
            $product->id,
            8
        );

        $this->assertSame(8.0, $result['requested_quantity']);
        $this->assertSame(5.0, $result['fulfilled_quantity']);
        $this->assertSame(3.0, $result['shortfall_quantity']);

        $this->assertDatabaseHas('inventories', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 0,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'type' => InventoryMovementType::SALE->value,
            'quantity' => 5,
        ]);
    }

    public function test_sell_stock_never_creates_negative_inventory(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 2,
            'reorder_level' => 1,
        ]);

        $result = $this->inventoryService->sellStock(
            $tenant->id,
            $branch->id,
            $product->id,
            10
        );

        $inventory = Inventory::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('catalog_item_id', $product->id)
            ->firstOrFail();

        $this->assertSame(0.0, (float) $inventory->quantity);
        $this->assertGreaterThanOrEqual(0, (float) $inventory->quantity);
        $this->assertSame(8.0, $result['shortfall_quantity']);
    }

    public function test_selling_a_service_does_not_change_inventory(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $service = CatalogItem::factory()->service()->create([
            'tenant_id' => $tenant->id,
            'track_inventory' => false,
        ]);

        $result = $this->inventoryService->sellStock(
            $tenant->id,
            $branch->id,
            $service->id,
            3
        );

        $this->assertSame(3.0, $result['fulfilled_quantity']);
        $this->assertSame(0, $result['shortfall_quantity']);
        $this->assertFalse($result['tracks_inventory']);

        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_tenant_cannot_use_another_tenants_catalog_item(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $productB = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $this->expectException(
            \Illuminate\Database\Eloquent\ModelNotFoundException::class
        );

        $this->inventoryService->checkAvailability(
            $tenantA->id,
            $branchA->id,
            $productB->id,
            1
        );
    }

    public function test_zero_or_negative_quantities_are_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->expectException(RuntimeException::class);

        $this->inventoryService->sellStock(
            $tenant->id,
            $branch->id,
            $product->id,
            0
        );
    }
}