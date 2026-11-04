<?php

namespace App\Services;

use App\InventoryMovementType;
use App\Models\CatalogItem;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryService
{
    /**
     * Check current stock availability for a product at a branch.
     */
    public function checkAvailability(
        int $tenantId,
        int $branchId,
        int $catalogItemId,
        float $requestedQuantity
    ): array {
        if ($requestedQuantity <= 0) {
            throw new RuntimeException(
                'Requested quantity must be greater than zero.'
            );
        }

        $catalogItem = CatalogItem::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($catalogItemId)
            ->firstOrFail();

        // Services do not use inventory.
        if (! $catalogItem->track_inventory) {
            return [
                'catalog_item_id' => $catalogItemId,
                'branch_id' => $branchId,
                'requested_quantity' => $requestedQuantity,
                'available_quantity' => null,
                'shortfall_quantity' => 0,
                'can_fulfill' => true,
                'tracks_inventory' => false,
            ];
        }

        $inventory = Inventory::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('catalog_item_id', $catalogItemId)
            ->first();

        $available = $inventory
            ? (float) $inventory->quantity
            : 0.0;

        $shortfall = max(
            0,
            $requestedQuantity - $available
        );

        return [
            'catalog_item_id' => $catalogItemId,
            'branch_id' => $branchId,
            'requested_quantity' => $requestedQuantity,
            'available_quantity' => $available,
            'shortfall_quantity' => $shortfall,
            'can_fulfill' => $shortfall <= 0,
            'tracks_inventory' => true,
        ];
    }

    /**
     * Receive physical stock into a branch.
     */
    
public function receiveStock(
    int $tenantId,
    int $branchId,
    int $catalogItemId,
    float $quantity,
    ?string $notes = null,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $batchNumber = null,
    ?float $unitCost = null,
    ?float $sellingPrice = null
): Inventory {
    if ($quantity <= 0) {
        throw new RuntimeException(
            'Received quantity must be greater than zero.'
        );
    }

    return DB::transaction(function () use (
        $tenantId,
        $branchId,
        $catalogItemId,
        $quantity,
        $notes,
        $referenceType,
        $referenceId,
        $batchNumber,
        $unitCost,
        $sellingPrice
    ) {
        $branch = Branch::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($branchId)
            ->firstOrFail();

        $catalogItem = CatalogItem::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($catalogItemId)
            ->firstOrFail();

        if (! $catalogItem->track_inventory) {
            throw new RuntimeException(
                'This catalog item does not track inventory.'
            );
        }

        $inventory = Inventory::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('catalog_item_id', $catalogItemId)
            ->lockForUpdate()
            ->first();

        if (! $inventory) {
            $inventory = Inventory::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'catalog_item_id' => $catalogItemId,
                'quantity' => 0,
                'reorder_level' => 0,
            ]);
        }

        // Represent any pre-existing aggregate stock before adding the new receipt.
        $this->ensureBatchCoverage($inventory, $catalogItem);

        // Generate a batch number when one is not supplied.
        $resolvedBatchNumber = $batchNumber
            ?: 'PUR-' . strtoupper((string) \Illuminate\Support\Str::uuid());

        // Prevent duplicate batch numbers for this product at this branch.
        $duplicateBatch = InventoryBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('catalog_item_id', $catalogItemId)
            ->where('batch_number', $resolvedBatchNumber)
            ->exists();

        if ($duplicateBatch) {
            throw new RuntimeException(
                'This batch number already exists for this product at this branch.'
            );
        }

        $inventory->quantity += $quantity;
        $inventory->save();

        $batch = InventoryBatch::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'catalog_item_id' => $catalogItemId,
            'batch_number' => $resolvedBatchNumber,
            'quantity_received' => $quantity,
            'quantity_remaining' => $quantity,
            'unit_cost' => $unitCost,
            'selling_price' => $sellingPrice,
            'received_at' => now(),
            'source_type' => 'purchase',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => auth()->id(),
        ]);

        $this->recordBatchMovement(
            $batch,
            'purchase',
            $quantity,
            $referenceType,
            $referenceId,
            $notes
        );

        InventoryMovement::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'catalog_item_id' => $catalogItemId,
            'type' => InventoryMovementType::PURCHASE,
            'quantity' => $quantity,
            'notes' => $notes,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        return $inventory->fresh();
    });
}


/**
 * Quote and lock the selling price for an order line.
 *
 * Uses FIFO batch selling prices for available stock.
 * Uses the catalogue selling price for batches without
 * a selling-price override and for any unstocked quantity.
 *
 * This method does not reserve or deduct stock.
 */
public function quoteOrderUnitSellingPrice(
    int $tenantId,
    int $catalogItemId,
    float $requestedQuantity,
    ?int $branchId = null
): float {
    if ($requestedQuantity <= 0) {
        throw new RuntimeException(
            'Order quantity must be greater than zero.'
        );
    }

    return DB::transaction(function () use (
        $tenantId,
        $catalogItemId,
        $requestedQuantity,
        $branchId
    ) {
        $catalogItem = CatalogItem::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($catalogItemId)
            ->firstOrFail();

        $catalogPrice = (float) $catalogItem->selling_price;

        if (! $catalogItem->track_inventory) {
            return $catalogPrice;
        }

        // Infer the branch only when stock exists at one branch.
        if ($branchId === null) {
            $branchIds = Inventory::query()
                ->where('tenant_id', $tenantId)
                ->where('catalog_item_id', $catalogItemId)
                ->where('quantity', '>', 0)
                ->distinct()
                ->orderBy('branch_id')
                ->limit(2)
                ->pluck('branch_id');

            if ($branchIds->count() > 1) {
                throw new RuntimeException(
                    'This product has stock at multiple branches. '
                    . 'Select a branch before creating the order.'
                );
            }

            $branchId = $branchIds->first()
                ? (int) $branchIds->first()
                : null;
        }

        if ($branchId === null) {
            return $catalogPrice;
        }

        $inventory = Inventory::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('catalog_item_id', $catalogItemId)
            ->lockForUpdate()
            ->first();

        if (! $inventory || (float) $inventory->quantity <= 0) {
            return $catalogPrice;
        }

        $this->ensureBatchCoverage($inventory, $catalogItem);

        $remaining = $requestedQuantity;
        $totalSellingValue = 0.0;

        $batches = InventoryBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('catalog_item_id', $catalogItemId)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0.000001) {
                break;
            }

            $allocated = min(
                $remaining,
                (float) $batch->quantity_remaining
            );

            if ($allocated <= 0) {
                continue;
            }

            $unitPrice = $batch->selling_price !== null
                ? (float) $batch->selling_price
                : $catalogPrice;

            $totalSellingValue += $allocated * $unitPrice;
            $remaining -= $allocated;
        }

        // Preserve the existing partial-fulfillment workflow.
        // Price any quantity not currently in stock at catalogue price.
        if ($remaining > 0.000001) {
            $totalSellingValue += $remaining * $catalogPrice;
        }

        return round(
            $totalSellingValue / $requestedQuantity,
            2
        );
    });
}
    /**
     * Sell stock from a branch.
     *
     * Returns the quantity that can actually be fulfilled and
     * the remaining shortage.
     */
    public function sellStock(
        int $tenantId,
        int $branchId,
        int $catalogItemId,
        float $requestedQuantity,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $sourceItemId = null
        ): array {
        if ($requestedQuantity <= 0) {
            throw new RuntimeException(
                'Requested quantity must be greater than zero.'
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $branchId,
            $catalogItemId,
            $requestedQuantity,
            $referenceType,
            $referenceId,
            $sourceItemId
        ) {
            $catalogItem = CatalogItem::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($catalogItemId)
                ->firstOrFail();

            if (! $catalogItem->track_inventory) {
                return [
                    'requested_quantity' => $requestedQuantity,
                    'fulfilled_quantity' => $requestedQuantity,
                    'shortfall_quantity' => 0,
                    'remaining_quantity' => 0,
                    'tracks_inventory' => false,
                ];
            }

            $inventory = Inventory::query()
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('catalog_item_id', $catalogItemId)
                ->lockForUpdate()
                ->first();

            $available = $inventory
                ? (float) $inventory->quantity
                : 0.0;

            $fulfilled = min($requestedQuantity, $available);
            $shortfall = $requestedQuantity - $fulfilled;
            $weightedSellingPrice = 0.0;

            if ($fulfilled > 0 && $inventory) {
                $this->ensureBatchCoverage($inventory, $catalogItem);

                $remainingToAllocate = $fulfilled;
                $totalSellingValue = 0.0;
                $batches = \App\Models\InventoryBatch::query()
                    ->where('tenant_id', $tenantId)
                    ->where('branch_id', $branchId)
                    ->where('catalog_item_id', $catalogItemId)
                    ->where('quantity_remaining', '>', 0)
                    ->orderBy('received_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($batches as $batch) {
                    if ($remainingToAllocate <= 0.000001) {
                        break;
                    }

                    $allocated = min($remainingToAllocate, (float) $batch->quantity_remaining);
                    if ($allocated <= 0) {
                        continue;
                    }

                    $unitSellingPrice = $batch->selling_price !== null
                        ? (float) $batch->selling_price
                        : (float) $catalogItem->selling_price;

                    $batch->quantity_remaining = (float) $batch->quantity_remaining - $allocated;
                    $batch->save();

                    $this->recordBatchMovement(
                        $batch,
                        'sale',
                        $allocated,
                        $referenceType,
                        $referenceId,
                        $shortfall > 0 ? 'Partial fulfillment due to insufficient stock.' : null
                    );

                    \App\Models\InventoryBatchAllocation::create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'catalog_item_id' => $catalogItemId,
                        'inventory_batch_id' => $batch->id,
                        'source_type' => $referenceType ?? 'sale',
                        'source_id' => $referenceId ?? 0,
                        'source_item_id' => $sourceItemId,
                        'quantity' => $allocated,
                        'unit_cost_snapshot' => $batch->unit_cost,
                        'unit_selling_price_snapshot' => $unitSellingPrice,
                    ]);

                    $totalSellingValue += $allocated * $unitSellingPrice;
                    $remainingToAllocate -= $allocated;
                }

                if ($remainingToAllocate > 0.000001) {
                    throw new RuntimeException('Inventory batch quantities do not reconcile with the aggregate stock balance.');
                }

                $weightedSellingPrice = $fulfilled > 0
                    ? round($totalSellingValue / $fulfilled, 2)
                    : 0.0;

                $inventory->quantity = max(0.0, (float) $inventory->quantity - $fulfilled);
                $inventory->save();

                InventoryMovement::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'catalog_item_id' => $catalogItemId,
                    'type' => InventoryMovementType::SALE,
                    'quantity' => $fulfilled,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'notes' => $shortfall > 0
                        ? 'Partial fulfillment due to insufficient stock.'
                        : null,
                ]);
            }

            return [
                'requested_quantity' => $requestedQuantity,
                'fulfilled_quantity' => $fulfilled,
                'shortfall_quantity' => $shortfall,
                'remaining_quantity' => max(0, $requestedQuantity - $fulfilled),
                'tracks_inventory' => true,
                'weighted_unit_selling_price' => $weightedSellingPrice,
            ];
        });
    }

    public function adjustStock(int $tenantId, int $branchId, int $catalogItemId, float $quantity, ?string $notes = null): Inventory
    {
        if ($quantity === 0.0) {
            throw new RuntimeException('Adjustment quantity cannot be zero.');
        }

        return DB::transaction(function () use ($tenantId, $branchId, $catalogItemId, $quantity, $notes) {
            Branch::query()->where('tenant_id', $tenantId)->whereKey($branchId)->firstOrFail();
            $catalogItem = CatalogItem::query()->where('tenant_id', $tenantId)->whereKey($catalogItemId)->firstOrFail();

            if (! $catalogItem->track_inventory) {
                throw new RuntimeException('This catalog item does not track inventory.');
            }

            $inventory = Inventory::query()
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('catalog_item_id', $catalogItemId)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                throw new RuntimeException('Inventory record not found.');
            }

            $this->ensureBatchCoverage($inventory, $catalogItem);
            $newQuantity = (float) $inventory->quantity + $quantity;
            if ($newQuantity < -0.000001) {
                throw new RuntimeException('Stock adjustment cannot result in negative inventory.');
            }

            if ($quantity > 0) {
                $batch = \App\Models\InventoryBatch::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'catalog_item_id' => $catalogItemId,
                    'batch_number' => 'ADJ-' . strtoupper((string) \Illuminate\Support\Str::uuid()),
                    'quantity_received' => $quantity,
                    'quantity_remaining' => $quantity,
                    'unit_cost' => null,
                    'selling_price' => null,
                    'received_at' => now(),
                    'source_type' => 'adjustment',
                    'reference_type' => 'inventory_adjustment',
                    'reference_id' => null,
                    'created_by' => auth()->id(),
                ]);
                $this->recordBatchMovement($batch, 'adjustment_in', $quantity, 'inventory_adjustment', null, $notes);
            } else {
                $this->consumeBatches($tenantId, $branchId, $catalogItemId, abs($quantity), 'adjustment_out', 'inventory_adjustment', null, $notes);
            }

            $inventory->quantity = max(0.0, $newQuantity);
            $inventory->save();

            InventoryMovement::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'catalog_item_id' => $catalogItemId,
                'type' => InventoryMovementType::ADJUSTMENT,
                'quantity' => $quantity,
                'notes' => $notes,
            ]);

            return $inventory->fresh();
        });
    }

    public function transferStock(int $tenantId, int $fromBranchId, int $toBranchId, int $catalogItemId, float $quantity, ?string $notes = null): array
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Transfer quantity must be greater than zero.');
        }
        if ($fromBranchId === $toBranchId) {
            throw new RuntimeException('Source and destination branches must be different.');
        }

        return DB::transaction(function () use ($tenantId, $fromBranchId, $toBranchId, $catalogItemId, $quantity, $notes) {
            Branch::query()->where('tenant_id', $tenantId)->whereKey($fromBranchId)->firstOrFail();
            Branch::query()->where('tenant_id', $tenantId)->whereKey($toBranchId)->firstOrFail();
            $catalogItem = CatalogItem::query()->where('tenant_id', $tenantId)->whereKey($catalogItemId)->firstOrFail();

            if (! $catalogItem->track_inventory) {
                throw new RuntimeException('This catalog item does not track inventory.');
            }

            $inventories = Inventory::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('branch_id', [$fromBranchId, $toBranchId])
                ->where('catalog_item_id', $catalogItemId)
                ->orderBy('branch_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('branch_id');

            $fromInventory = $inventories->get($fromBranchId);
            if (! $fromInventory) {
                throw new RuntimeException('Source inventory record not found.');
            }
            if ((float) $fromInventory->quantity < $quantity) {
                throw new RuntimeException('Insufficient stock for transfer.');
            }

            $this->ensureBatchCoverage($fromInventory, $catalogItem);
            $toInventory = $inventories->get($toBranchId);
            if (! $toInventory) {
                $toInventory = Inventory::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $toBranchId,
                    'catalog_item_id' => $catalogItemId,
                    'quantity' => 0,
                    'reorder_level' => 0,
                ]);
            }

            $remaining = $quantity;
            $batches = \App\Models\InventoryBatch::query()
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $fromBranchId)
                ->where('catalog_item_id', $catalogItemId)
                ->where('quantity_remaining', '>', 0)
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($batches as $sourceBatch) {
                if ($remaining <= 0.000001) {
                    break;
                }
                $moved = min($remaining, (float) $sourceBatch->quantity_remaining);
                if ($moved <= 0) {
                    continue;
                }

                $sourceBatch->quantity_remaining = (float) $sourceBatch->quantity_remaining - $moved;
                $sourceBatch->save();
                $this->recordBatchMovement($sourceBatch, 'transfer_out', $moved, 'inventory_transfer', null, $notes);

                $destinationBatch = \App\Models\InventoryBatch::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $toBranchId,
                    'catalog_item_id' => $catalogItemId,
                    'batch_number' => 'TRF-' . strtoupper((string) \Illuminate\Support\Str::uuid()),
                    'quantity_received' => $moved,
                    'quantity_remaining' => $moved,
                    'unit_cost' => $sourceBatch->unit_cost,
                    'selling_price' => $sourceBatch->selling_price,
                    'received_at' => $sourceBatch->received_at,
                    'source_type' => 'transfer',
                    'reference_type' => 'inventory_transfer',
                    'reference_id' => null,
                    'origin_batch_id' => $sourceBatch->origin_batch_id ?: $sourceBatch->id,
                    'created_by' => auth()->id(),
                ]);
                $this->recordBatchMovement($destinationBatch, 'transfer_in', $moved, 'inventory_transfer', null, $notes);
                $remaining -= $moved;
            }

            if ($remaining > 0.000001) {
                throw new RuntimeException('Inventory batch quantities do not reconcile with the aggregate stock balance.');
            }

            $fromInventory->quantity = (float) $fromInventory->quantity - $quantity;
            $fromInventory->save();
            $toInventory->quantity = (float) $toInventory->quantity + $quantity;
            $toInventory->save();

            InventoryMovement::create([
                'tenant_id' => $tenantId,
                'branch_id' => $fromBranchId,
                'catalog_item_id' => $catalogItemId,
                'type' => InventoryMovementType::TRANSFER_OUT,
                'quantity' => $quantity,
                'notes' => $notes,
                'reference_type' => 'inventory_transfer',
            ]);
            InventoryMovement::create([
                'tenant_id' => $tenantId,
                'branch_id' => $toBranchId,
                'catalog_item_id' => $catalogItemId,
                'type' => InventoryMovementType::TRANSFER_IN,
                'quantity' => $quantity,
                'notes' => $notes,
                'reference_type' => 'inventory_transfer',
            ]);

            return [
                'from_inventory' => $fromInventory->fresh(),
                'to_inventory' => $toInventory->fresh(),
            ];
        });
    }

    public function setOpeningStock(int $tenantId,int $branchId,int $catalogItemId,float $quantity,?string $notes = null,?string $referenceType = null,?int $referenceId = null, ?float $unitCost = null,
    ?float $sellingPrice = null): Inventory 
    {
        if ($quantity < 0) {
            throw new RuntimeException(
                'Opening stock cannot be negative.'
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $branchId,
            $catalogItemId,
            $quantity,
            $notes,
            $referenceType,
            $referenceId,
            $unitCost,
            $sellingPrice
        ) {
            $branch = Branch::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($branchId)
                ->firstOrFail();

            $catalogItem = CatalogItem::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($catalogItemId)
                ->firstOrFail();

            if (! $catalogItem->track_inventory) {
                throw new RuntimeException(
                    'This catalog item does not track inventory.'
                );
            }

            $inventory = Inventory::query()
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('catalog_item_id', $catalogItemId)
                ->lockForUpdate()
                ->first();

            // Do not overwrite stock that has already been initialized
            // or received through normal inventory operations.
            if ($inventory !== null) {
                throw new RuntimeException(
                    'Inventory already exists for this product at this branch. '
                    . 'Use a stock adjustment or receipt instead.'
                );
            }

            $inventory = Inventory::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'catalog_item_id' => $catalogItemId,
                'quantity' => $quantity,
                'reorder_level' => 0,
            ]);

            // Keep the batch ledger aligned with the opening aggregate balance.
            if ($quantity > 0) {
                $batch = \App\Models\InventoryBatch::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'catalog_item_id' => $catalogItemId,
                    'batch_number' => 'OPEN-' . strtoupper((string) \Illuminate\Support\Str::uuid()),
                    'quantity_received' => $quantity,
                    'quantity_remaining' => $quantity,
                    'unit_cost' => null,
                    'selling_price' => null,
                    'received_at' => now(),
                    'source_type' => 'opening',
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'unit_cost' => $unitCost,
                    'selling_price' => $sellingPrice,
                    'created_by' => auth()->id(),
                ]);
                $this->recordBatchMovement($batch, 'opening', $quantity, $referenceType, $referenceId, $notes);

                InventoryMovement::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'catalog_item_id' => $catalogItemId,
                    'type' => InventoryMovementType::OPENING,
                    'quantity' => $quantity,
                    'notes' => $notes,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]);
            }

            return $inventory->fresh();
        });
    }


    /**
     * Ensure legacy aggregate stock is represented by batches before consuming it.
     */
    private function ensureBatchCoverage(Inventory $inventory, CatalogItem $catalogItem): void
    {
        $batchQuantity = (float) \App\Models\InventoryBatch::query()
            ->where('tenant_id', $inventory->tenant_id)
            ->where('branch_id', $inventory->branch_id)
            ->where('catalog_item_id', $inventory->catalog_item_id)
            ->sum('quantity_remaining');

        $difference = round((float) $inventory->quantity - $batchQuantity, 3);

        if ($difference < -0.001) {
            throw new RuntimeException('Inventory batch quantities exceed the aggregate stock balance.');
        }

        if ($difference > 0.001) {
            $batch = \App\Models\InventoryBatch::create([
                'tenant_id' => $inventory->tenant_id,
                'branch_id' => $inventory->branch_id,
                'catalog_item_id' => $inventory->catalog_item_id,
                'batch_number' => 'LEGACY-' . strtoupper((string) \Illuminate\Support\Str::uuid()),
                'quantity_received' => $difference,
                'quantity_remaining' => $difference,
                'unit_cost' => null,
                'selling_price' => null,
                'received_at' => $inventory->created_at ?? now(),
                'source_type' => 'opening',
                'reference_type' => 'legacy_inventory',
                'reference_id' => $inventory->id,
                'created_by' => null,
            ]);

            $this->recordBatchMovement($batch, 'opening', $difference, 'legacy_inventory', (int) $inventory->id);
        }
    }

    private function consumeBatches(
        int $tenantId,
        int $branchId,
        int $catalogItemId,
        float $quantity,
        string $movementType,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null
    ): array {
        $remaining = $quantity;
        $consumed = [];

        $batches = \App\Models\InventoryBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('catalog_item_id', $catalogItemId)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0.000001) {
                break;
            }

            $take = min($remaining, (float) $batch->quantity_remaining);
            if ($take <= 0) {
                continue;
            }

            $batch->quantity_remaining = (float) $batch->quantity_remaining - $take;
            $batch->save();
            $this->recordBatchMovement($batch, $movementType, $take, $referenceType, $referenceId, $notes);
            $consumed[] = ['inventory_batch_id' => $batch->id, 'quantity' => $take];
            $remaining -= $take;
        }

        if ($remaining > 0.000001) {
            throw new RuntimeException('Inventory batch quantities do not reconcile with the aggregate stock balance.');
        }

        return $consumed;
    }

    private function recordBatchMovement(
        \App\Models\InventoryBatch $batch,
        string $type,
        float $quantity,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null
    ): void {
        \App\Models\InventoryBatchMovement::create([
            'tenant_id' => $batch->tenant_id,
            'branch_id' => $batch->branch_id,
            'catalog_item_id' => $batch->catalog_item_id,
            'inventory_batch_id' => $batch->id,
            'type' => $type,
            'quantity' => $quantity,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $notes,
            'created_by' => auth()->id(),
        ]);
    }

    public function restoreReturnedStock(
        int $tenantId,
        int $branchId,
        int $catalogItemId,
        int $batchId,
        float $quantity,
        int $returnItemId,
        ?string $notes = null
    ): Inventory 
    {
        if ($quantity <= 0) {
            throw new RuntimeException(
                'Returned quantity must be greater than zero.'
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $branchId,
            $catalogItemId,
            $batchId,
            $quantity,
            $returnItemId,
            $notes
        ) {
            Branch::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($branchId)
                ->firstOrFail();

            $catalogItem = CatalogItem::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($catalogItemId)
                ->firstOrFail();

            if (! $catalogItem->track_inventory) {
                throw new RuntimeException(
                    'This catalog item does not track inventory.'
                );
            }

            $batch = \App\Models\InventoryBatch::query()
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('catalog_item_id', $catalogItemId)
                ->whereKey($batchId)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency protection.
            $alreadyRestored = \App\Models\InventoryBatchMovement::query()
                ->where('tenant_id', $tenantId)
                ->where('inventory_batch_id', $batch->id)
                ->where('type', 'return')
                ->where('reference_type', 'employee_item_request_return_item')
                ->where('reference_id', $returnItemId)
                ->exists();

            if ($alreadyRestored) {
                throw new RuntimeException(
                    'Stock for this return item has already been restored.'
                );
            }

            $inventory = Inventory::query()
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('catalog_item_id', $catalogItemId)
                ->lockForUpdate()
                ->firstOrFail();

            $newBatchQuantity =
                (float) $batch->quantity_remaining + $quantity;

            if (
                $newBatchQuantity >
                (float) $batch->quantity_received + 0.000001
            ) {
                throw new RuntimeException(
                    'Returned quantity exceeds the original batch quantity.'
                );
            }

            $batch->quantity_remaining = $newBatchQuantity;
            $batch->save();

            $this->recordBatchMovement(
                $batch,
                'return',
                $quantity,
                'employee_item_request_return_item',
                $returnItemId,
                $notes
            );

            $inventory->quantity =
                (float) $inventory->quantity + $quantity;

            $inventory->save();

            InventoryMovement::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'catalog_item_id' => $catalogItemId,
                'type' => InventoryMovementType::RETURN,
                'quantity' => $quantity,
                'reference_type' => 'employee_item_request_return_item',
                'reference_id' => $returnItemId,
                'notes' => $notes,
            ]);

            return $inventory->fresh();
        });
    }
}
