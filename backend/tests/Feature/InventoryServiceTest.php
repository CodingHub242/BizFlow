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


public function test_set_opening_stock_creates_inventory_and_opening_movement(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $product = CatalogItem::factory()->product()->create([
        'tenant_id' => $tenant->id,
        'track_inventory' => true,
    ]);

    $inventory = $this->inventoryService->setOpeningStock(
        $tenant->id,
        $branch->id,
        $product->id,
        25,
        'Initial stock count',
        'migration',
        123
    );

    $this->assertEquals(25.0, (float) $inventory->quantity);

    $this->assertDatabaseHas('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $product->id,
        'quantity' => 25,
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $product->id,
        'type' => InventoryMovementType::OPENING->value,
        'quantity' => 25,
        'notes' => 'Initial stock count',
        'reference_type' => 'migration',
        'reference_id' => 123,
    ]);
}

public function test_set_opening_stock_rejects_negative_quantity(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $product = CatalogItem::factory()->product()->create([
        'tenant_id' => $tenant->id,
        'track_inventory' => true,
    ]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage(
        'Opening stock cannot be negative.'
    );

    $this->inventoryService->setOpeningStock(
        $tenant->id,
        $branch->id,
        $product->id,
        -1
    );
}

public function test_set_opening_stock_rejects_non_inventory_items(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $service = CatalogItem::factory()->service()->create([
        'tenant_id' => $tenant->id,
        'track_inventory' => false,
    ]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage(
        'This catalog item does not track inventory.'
    );

    $this->inventoryService->setOpeningStock(
        $tenant->id,
        $branch->id,
        $service->id,
        10
    );
}

public function test_set_opening_stock_does_not_overwrite_existing_inventory(): void
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

    try {
        $this->inventoryService->setOpeningStock(
            $tenant->id,
            $branch->id,
            $product->id,
            50
        );

        $this->fail('Expected opening stock initialization to fail.');
    } catch (RuntimeException $exception) {
        $this->assertStringContainsString(
            'Inventory already exists',
            $exception->getMessage()
        );
    }

    $this->assertDatabaseHas('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $product->id,
        'quantity' => 10,
    ]);

    $this->assertDatabaseCount('inventory_movements', 0);
}

public function test_set_opening_stock_allows_zero_without_movement(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $product = CatalogItem::factory()->product()->create([
        'tenant_id' => $tenant->id,
        'track_inventory' => true,
    ]);

    $inventory = $this->inventoryService->setOpeningStock(
        $tenant->id,
        $branch->id,
        $product->id,
        0
    );

    $this->assertEquals(0.0, (float) $inventory->quantity);

    $this->assertDatabaseCount('inventories', 1);
    $this->assertDatabaseCount('inventory_movements', 0);
}


    public function test_receiving_stock_creates_a_batch_and_batch_movement(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
            'track_inventory' => true,
        ]);

        $this->inventoryService->receiveStock($tenant->id, $branch->id, $product->id, 12);

        $this->assertDatabaseHas('inventory_batches', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity_received' => 12,
            'quantity_remaining' => 12,
            'source_type' => 'purchase',
        ]);
        $this->assertDatabaseHas('inventory_batch_movements', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 12,
        ]);
    }

    public function test_sale_consumes_batches_in_fifo_order_and_returns_weighted_price(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
            'track_inventory' => true,
            'selling_price' => 20,
        ]);

        $inventory = Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 10,
            'reorder_level' => 0,
        ]);

        $first = \\App\\Models\\InventoryBatch::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'batch_number' => 'FIFO-001',
            'quantity_received' => 4,
            'quantity_remaining' => 4,
            'unit_cost' => 5,
            'selling_price' => 15,
            'received_at' => now()->subDay(),
            'source_type' => 'purchase',
        ]);
        $second = \\App\\Models\\InventoryBatch::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'batch_number' => 'FIFO-002',
            'quantity_received' => 6,
            'quantity_remaining' => 6,
            'unit_cost' => 8,
            'selling_price' => 25,
            'received_at' => now(),
            'source_type' => 'purchase',
        ]);

        $result = $this->inventoryService->sellStock(
            $tenant->id, $branch->id, $product->id, 5, 'test_sale', 999
        );

        $this->assertSame(5.0, $result['fulfilled_quantity']);
        $this->assertSame(23.0, $result['weighted_unit_selling_price']);
        $this->assertEquals(0, (float) $first->fresh()->quantity_remaining);
        $this->assertEquals(5, (float) $second->fresh()->quantity_remaining);
        $this->assertEquals(5, (float) $inventory->fresh()->quantity);
        $this->assertDatabaseHas('inventory_batch_allocations', [
            'inventory_batch_id' => $first->id,
            'source_type' => 'test_sale',
            'source_id' => 999,
            'quantity' => 4,
        ]);
        $this->assertDatabaseHas('inventory_batch_allocations', [
            'inventory_batch_id' => $second->id,
            'source_type' => 'test_sale',
            'source_id' => 999,
            'quantity' => 1,
        ]);
    }

    public function test_positive_adjustment_adds_a_batch_and_negative_adjustment_consumes_fifo(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
            'track_inventory' => true,
        ]);
        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $product->id,
            'quantity' => 5,
            'reorder_level' => 0,
        ]);

        $this->inventoryService->adjustStock($tenant->id, $branch->id, $product->id, 3, 'count gain');
        $this->assertEquals(8, (float) Inventory::where('catalog_item_id', $product->id)->value('quantity'));
        $this->assertDatabaseHas('inventory_batches', [
            'catalog_item_id' => $product->id,
            'source_type' => 'adjustment',
            'quantity_remaining' => 3,
        ]);

        $this->inventoryService->adjustStock($tenant->id, $branch->id, $product->id, -2, 'count loss');
        $this->assertEquals(6, (float) Inventory::where('catalog_item_id', $product->id)->value('quantity'));
        $this->assertEquals(
            6,
            (float) \\App\\Models\\InventoryBatch::where('tenant_id', $tenant->id)
                ->where('branch_id', $branch->id)
                ->where('catalog_item_id', $product->id)
                ->sum('quantity_remaining')
        );
    }

    public function test_transfer_preserves_batch_cost_price_and_lineage(): void
    {
        $tenant = Tenant::factory()->create();
        $from = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $to = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
            'track_inventory' => true,
        ]);
        Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $from->id,
            'catalog_item_id' => $product->id,
            'quantity' => 5,
            'reorder_level' => 0,
        ]);
        $source = \\App\\Models\\InventoryBatch::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $from->id,
            'catalog_item_id' => $product->id,
            'batch_number' => 'TRANSFER-SOURCE',
            'quantity_received' => 5,
            'quantity_remaining' => 5,
            'unit_cost' => 7.5,
            'selling_price' => 18,
            'received_at' => now()->subDay(),
            'source_type' => 'purchase',
        ]);

        $result = $this->inventoryService->transferStock(
            $tenant->id, $from->id, $to->id, $product->id, 3
        );

        $destinationBatch = \\App\\Models\\InventoryBatch::where('branch_id', $to->id)
            ->where('catalog_item_id', $product->id)
            ->firstOrFail();

        $this->assertEquals(2, (float) $source->fresh()->quantity_remaining);
        $this->assertEquals(3, (float) $destinationBatch->quantity_remaining);
        $this->assertEquals(7.5, (float) $destinationBatch->unit_cost);
        $this->assertEquals(18, (float) $destinationBatch->selling_price);
        $this->assertEquals($source->id, $destinationBatch->origin_batch_id);
        $this->assertEquals(2, (float) $result['from_inventory']->quantity);
        $this->assertEquals(3, (float) $result['to_inventory']->quantity);
    }

}
