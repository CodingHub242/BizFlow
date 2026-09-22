<?php

namespace App\Services;

use App\InventoryMovementType;
use App\Models\CatalogItem;
use App\Models\Inventory;
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
        ?int $referenceId = null
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
            $referenceId
        ) {
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

            $inventory->quantity += $quantity;
            $inventory->save();

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
        ?int $referenceId = null
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
            $referenceId
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

            $fulfilled = min(
                $requestedQuantity,
                $available
            );

            $shortfall = $requestedQuantity - $fulfilled;

            if ($fulfilled > 0) {
                $inventory->quantity -= $fulfilled;
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
                'remaining_quantity' => max(
                    0,
                    $requestedQuantity - $fulfilled
                ),
                'tracks_inventory' => true,
            ];
        });
    }
}