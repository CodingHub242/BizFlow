<?php

namespace App\Services;

use App\FulfillmentStatus;
use App\OrderStatus;
use App\Models\FulfillmentRequest;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderFulfillmentService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {
    }

    /**
     * Fulfill an order using the stock available at its branch.
     *
     * Products that track inventory are deducted through InventoryService.
     * Services bypass inventory.
     *
     * If stock is insufficient, the available quantity is fulfilled and
     * a FulfillmentRequest records the remaining shortage.
     */
    public function fulfill(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::query()
                ->where('tenant_id', $order->tenant_id)
                ->whereKey($order->id)
                ->with('items.catalogItem')
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === OrderStatus::CANCELLED) {
                throw new RuntimeException(
                    'A cancelled order cannot be fulfilled.'
                );
            }

            foreach ($order->items as $item) {
                $catalogItem = $item->catalogItem;

                if (! $catalogItem) {
                    throw new RuntimeException(
                        "Catalog item for order item {$item->id} was not found."
                    );
                }

                if ($catalogItem->tenant_id !== $order->tenant_id) {
                    throw new RuntimeException(
                        'Order and catalog item belong to different tenants.'
                    );
                }

                if (! $catalogItem->track_inventory) {
                    continue;
                }

                $result = $this->inventoryService->sellStock(
                    tenantId: $order->tenant_id,
                    branchId: $order->branch_id,
                    catalogItemId: $catalogItem->id,
                    requestedQuantity: (float) $item->quantity,
                    referenceType: Order::class,
                    referenceId: $order->id,
                );

                if ($result['shortfall_quantity'] > 0) {
                    FulfillmentRequest::updateOrCreate(
                        [
                            'tenant_id' => $order->tenant_id,
                            'order_id' => $order->id,
                            'order_item_id' => $item->id,
                        ],
                        [
                            'branch_id' => $order->branch_id,
                            'catalog_item_id' => $catalogItem->id,
                            'requested_quantity' => $item->quantity,
                            'available_quantity' => $result['fulfilled_quantity'],
                            'fulfilled_quantity' => $result['fulfilled_quantity'],
                            'shortfall_quantity' => $result['shortfall_quantity'],
                            'status' => $result['fulfilled_quantity'] > 0
                                ? FulfillmentStatus::PARTIALLY_FULFILLED
                                : FulfillmentStatus::PENDING,
                        ]
                    );
                }
            }

            $hasShortage = FulfillmentRequest::query()
                ->where('tenant_id', $order->tenant_id)
                ->where('order_id', $order->id)
                ->whereIn('status', [
                    FulfillmentStatus::PENDING,
                    FulfillmentStatus::SOURCING,
                    FulfillmentStatus::PARTIALLY_FULFILLED,
                ])
                ->exists();

            $order->status = $hasShortage
                ? OrderStatus::PARTIALLY_FULFILLED
                : OrderStatus::FULFILLED;

            $order->save();

            return $order->fresh([
                'items.catalogItem',
                'fulfillmentRequests',
            ]);
        });
    }
}