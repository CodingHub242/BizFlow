<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\Inventory;
use App\Models\Tenant;
use App\Models\User;
use App\CatalogItemType;
use App\InventoryMovementType;
use App\PurchaseStatus;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_service_can_create_purchase_with_items(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $supplier = Supplier::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
            'selling_price' => 150,
            'track_inventory' => true,
        ]);

        $purchase = app(PurchaseService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'purchase_number' => 'PUR-TEST-001',

            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 10,
                    'unit_price' => 85,
                ],
            ],
        ]);

        $this->assertInstanceOf(
            Purchase::class,
            $purchase
        );

        $this->assertCount(
            1,
            $purchase->items
        );

        $item = $purchase->items->first();

        $this->assertEquals(
            $product->id,
            $item->catalog_item_id
        );

        $this->assertEquals(
            '85.00',
            $item->unit_price
        );

        $this->assertEquals(
            '10.000',
            $item->quantity
        );

        $this->assertEquals(
            '850.00',
            $item->line_total
        );

        $this->assertEquals(
            '850.00',
            $purchase->subtotal
        );

        $this->assertEquals(
            '850.00',
            $purchase->total
        );
    }

    public function test_purchase_service_can_create_draft_without_items(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $supplier = Supplier::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $purchase = app(PurchaseService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'purchase_number' => 'PUR-DRAFT-001',
        ]);

        $this->assertEquals(
            'draft',
            $purchase->status->value
        );

        $this->assertCount(
            0,
            $purchase->items
        );
    }

    public function test_purchase_service_rejects_supplier_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $supplierB = Supplier::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->expectException(
            ValidationException::class
        );

        app(PurchaseService::class)->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'supplier_id' => $supplierB->id,
            'created_by' => $userA->id,
            'purchase_number' => 'PUR-CROSS-SUPPLIER-001',
        ]);
    }

    public function test_purchase_service_rejects_branch_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchB = Branch::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $supplierA = Supplier::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->expectException(
            \Illuminate\Database\Eloquent\ModelNotFoundException::class
        );

        app(PurchaseService::class)->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchB->id,
            'supplier_id' => $supplierA->id,
            'created_by' => $userA->id,
            'purchase_number' => 'PUR-CROSS-BRANCH-001',
        ]);
    }

    public function test_purchase_service_rejects_creator_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $supplierA = Supplier::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $userB = User::factory()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $this->expectException(
            ValidationException::class
        );

        app(PurchaseService::class)->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'supplier_id' => $supplierA->id,
            'created_by' => $userB->id,
            'purchase_number' => 'PUR-CROSS-USER-001',
        ]);
    }

    public function test_purchase_service_rejects_catalog_item_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $supplierA = Supplier::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $productB = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenantB->id,
        ]);

        $this->expectException(
            ValidationException::class
        );

        app(PurchaseService::class)->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'supplier_id' => $supplierA->id,
            'created_by' => $userA->id,
            'purchase_number' => 'PUR-CROSS-CATALOG-001',

            'items' => [
                [
                    'catalog_item_id' => $productB->id,
                    'quantity' => 10,
                    'unit_price' => 85,
                ],
            ],
        ]);
    }

    public function test_purchase_service_rejects_inactive_catalog_item(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $supplier = Supplier::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
            'is_active' => false,
        ]);

        $this->expectException(
            ValidationException::class
        );

        app(PurchaseService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'purchase_number' => 'PUR-INACTIVE-001',

            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 10,
                    'unit_price' => 85,
                ],
            ],
        ]);
    }

    public function test_purchase_service_rejects_invalid_quantity(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $supplier = Supplier::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->expectException(
            ValidationException::class
        );

        app(PurchaseService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'purchase_number' => 'PUR-INVALID-QTY-001',

            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 0,
                    'unit_price' => 85,
                ],
            ],
        ]);
    }

    public function test_purchase_service_rejects_negative_purchase_price(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $supplier = Supplier::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $product = CatalogItem::factory()->product()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->expectException(
            ValidationException::class
        );

        app(PurchaseService::class)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'purchase_number' => 'PUR-NEGATIVE-PRICE-001',

            'items' => [
                [
                    'catalog_item_id' => $product->id,
                    'quantity' => 10,
                    'unit_price' => -85,
                ],
            ],
        ]);
    }

    public function test_purchase_can_be_partially_received(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();

        $supplier = Supplier::factory()->for($tenant)->create();

        $user = User::factory()->for($tenant)->create();

        $catalogItem = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'type' => CatalogItemType::PRODUCT,
                'track_inventory' => true,
            ]);

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'status' => PurchaseStatus::ORDERED,
            ]);

        $item = PurchaseItem::factory()
            ->for($tenant)
            ->create([
                'purchase_id' => $purchase->id,
                'catalog_item_id' => $catalogItem->id,
                'quantity' => 100,
                'received_quantity' => 0,
            ]);

        $purchase->load('items');

        $service = app(PurchaseService::class);

        $service->receive($purchase, [
            [
                'purchase_item_id' => $item->id,
                'quantity' => 60,
            ],
        ]);

        $item->refresh();
        $purchase->refresh();

        $this->assertEquals('60.000', $item->received_quantity);
        $this->assertEquals(
            PurchaseStatus::PARTIALLY_RECEIVED,
            $purchase->status
        );
    }

    public function test_purchase_becomes_received_when_all_items_are_received(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();

        $supplier = Supplier::factory()->for($tenant)->create();

        $user = User::factory()->for($tenant)->create();

        $catalogItem = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'type' => CatalogItemType::PRODUCT,
                'track_inventory' => true,
            ]);

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'status' => PurchaseStatus::ORDERED,
            ]);

        $item = PurchaseItem::factory()
            ->for($tenant)
            ->create([
                'purchase_id' => $purchase->id,
                'catalog_item_id' => $catalogItem->id,
                'quantity' => 100,
                'received_quantity' => 0,
            ]);

        $service = app(PurchaseService::class);

        // First receipt
        $service->receive($purchase, [
            [
                'purchase_item_id' => $item->id,
                'quantity' => 60,
            ],
        ]);

        // Remaining receipt
        $service->receive($purchase, [
            [
                'purchase_item_id' => $item->id,
                'quantity' => 40,
            ],
        ]);

        $item->refresh();
        $purchase->refresh();

        $this->assertEquals('100.000', $item->received_quantity);

        $this->assertEquals(
            PurchaseStatus::RECEIVED,
            $purchase->status
        );
    }

    public function test_purchase_cannot_receive_more_than_ordered_quantity(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();

        $supplier = Supplier::factory()->for($tenant)->create();

        $user = User::factory()->for($tenant)->create();

        $catalogItem = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'type' => CatalogItemType::PRODUCT,
                'track_inventory' => true,
            ]);

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'status' => PurchaseStatus::ORDERED,
            ]);

        $item = PurchaseItem::factory()
            ->for($tenant)
            ->create([
                'purchase_id' => $purchase->id,
                'catalog_item_id' => $catalogItem->id,
                'quantity' => 100,
                'received_quantity' => 60,
            ]);

        $service = app(PurchaseService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->receive($purchase, [
            [
                'purchase_item_id' => $item->id,
                'quantity' => 50,
            ],
        ]);
    }

    public function test_failed_receipt_does_not_change_inventory_or_received_quantity(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();

        $supplier = Supplier::factory()->for($tenant)->create();

        $user = User::factory()->for($tenant)->create();

        $catalogItem = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'type' => CatalogItemType::PRODUCT,
                'track_inventory' => true,
            ]);

        $inventory = Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 20,
            'reorder_level' => 0,
        ]);

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'status' => PurchaseStatus::ORDERED,
            ]);

        $item = PurchaseItem::factory()
            ->for($tenant)
            ->create([
                'purchase_id' => $purchase->id,
                'catalog_item_id' => $catalogItem->id,
                'quantity' => 100,
                'received_quantity' => 80,
            ]);

        $service = app(PurchaseService::class);

        try {
            $service->receive($purchase, [
                [
                    'purchase_item_id' => $item->id,
                    'quantity' => 25,
                ],
            ]);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Expected.
        }

        $item->refresh();
        $inventory->refresh();

        $this->assertEquals('80.000', $item->received_quantity);
        $this->assertEquals('20.000', $inventory->quantity);

        $this->assertDatabaseMissing('inventory_movements', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 25,
        ]);
    }

    public function test_purchase_with_multiple_items_can_be_partially_received(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();

        $supplier = Supplier::factory()->for($tenant)->create();

        $user = User::factory()->for($tenant)->create();

        $itemOne = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'type' => CatalogItemType::PRODUCT,
                'track_inventory' => true,
            ]);

        $itemTwo = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'type' => CatalogItemType::PRODUCT,
                'track_inventory' => true,
            ]);

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'status' => PurchaseStatus::ORDERED,
            ]);

        $purchaseItemOne = PurchaseItem::factory()
            ->for($tenant)
            ->create([
                'purchase_id' => $purchase->id,
                'catalog_item_id' => $itemOne->id,
                'quantity' => 10,
                'received_quantity' => 0,
            ]);

        $purchaseItemTwo = PurchaseItem::factory()
            ->for($tenant)
            ->create([
                'purchase_id' => $purchase->id,
                'catalog_item_id' => $itemTwo->id,
                'quantity' => 20,
                'received_quantity' => 0,
            ]);

        $service = app(PurchaseService::class);

        $service->receive($purchase, [
            [
                'purchase_item_id' => $purchaseItemOne->id,
                'quantity' => 10,
            ],
            [
                'purchase_item_id' => $purchaseItemTwo->id,
                'quantity' => 5,
            ],
        ]);

        $purchaseItemOne->refresh();
        $purchaseItemTwo->refresh();
        $purchase->refresh();

        $this->assertEquals('10.000', $purchaseItemOne->received_quantity);
        $this->assertEquals('5.000', $purchaseItemTwo->received_quantity);

        $this->assertEquals(
            PurchaseStatus::PARTIALLY_RECEIVED,
            $purchase->status
        );
    }

    public function test_purchase_with_multiple_items_becomes_received_when_all_items_are_received(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();

        $supplier = Supplier::factory()->for($tenant)->create();

        $user = User::factory()->for($tenant)->create();

        $itemOne = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'type' => CatalogItemType::PRODUCT,
                'track_inventory' => true,
            ]);

        $itemTwo = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'type' => CatalogItemType::PRODUCT,
                'track_inventory' => true,
            ]);

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'status' => PurchaseStatus::ORDERED,
            ]);

        $purchaseItemOne = PurchaseItem::factory()
            ->for($tenant)
            ->create([
                'purchase_id' => $purchase->id,
                'catalog_item_id' => $itemOne->id,
                'quantity' => 10,
                'received_quantity' => 0,
            ]);

        $purchaseItemTwo = PurchaseItem::factory()
            ->for($tenant)
            ->create([
                'purchase_id' => $purchase->id,
                'catalog_item_id' => $itemTwo->id,
                'quantity' => 20,
                'received_quantity' => 0,
            ]);

        $service = app(PurchaseService::class);

        $service->receive($purchase, [
            [
                'purchase_item_id' => $purchaseItemOne->id,
                'quantity' => 10,
            ],
            [
                'purchase_item_id' => $purchaseItemTwo->id,
                'quantity' => 20,
            ],
        ]);

        $purchaseItemOne->refresh();
        $purchaseItemTwo->refresh();
        $purchase->refresh();

        $this->assertEquals('10.000', $purchaseItemOne->received_quantity);
        $this->assertEquals('20.000', $purchaseItemTwo->received_quantity);

        $this->assertEquals(
            PurchaseStatus::RECEIVED,
            $purchase->status
        );
    }

    public function test_receiving_purchase_increases_inventory_and_creates_purchase_movement(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();

        $supplier = Supplier::factory()->for($tenant)->create();

        $user = User::factory()->for($tenant)->create();

        $catalogItem = CatalogItem::factory()
            ->for($tenant)
            ->create([
                'type' => CatalogItemType::PRODUCT,
                'track_inventory' => true,
            ]);

        $inventory = Inventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 10,
            'reorder_level' => 0,
        ]);

        $purchase = Purchase::factory()
            ->for($tenant)
            ->create([
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'status' => PurchaseStatus::ORDERED,
            ]);

        $purchaseItem = PurchaseItem::factory()
            ->for($tenant)
            ->create([
                'purchase_id' => $purchase->id,
                'catalog_item_id' => $catalogItem->id,
                'quantity' => 100,
                'received_quantity' => 0,
            ]);

        $service = app(PurchaseService::class);

        $service->receive($purchase, [
            [
                'purchase_item_id' => $purchaseItem->id,
                'quantity' => 60,
            ],
        ]);

        $inventory->refresh();

        $this->assertEquals('70.000', $inventory->quantity);

        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'type' => InventoryMovementType::PURCHASE->value,
            'quantity' => 60,
            'notes' => "Received from purchase {$purchase->purchase_number}",
            'reference_type' => Purchase::class,
            'reference_id' => $purchase->id,
        ]);
    }
}