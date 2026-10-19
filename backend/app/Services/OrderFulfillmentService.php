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
     * Fulfill an order incrementally.
     *
     * FulfillmentRequest.fulfilled_quantity stores the cumulative quantity
     * fulfilled for each inventory-tracked order item.
     */
    public function fulfill(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::query()
                ->where('tenant_id', $order->tenant_id)
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === OrderStatus::CANCELLED) {
                throw new RuntimeException(
                    'A cancelled order cannot be fulfilled.'
                );
            }

            $order->load('items.catalogItem');

            foreach ($order->items as $item) {
                $catalogItem = $item->catalogItem;

                if (! $catalogItem) {
                    throw new RuntimeException(
                        "Catalog item for order item {$item->id} was not found."
                    );
                }

                if ((int) $catalogItem->tenant_id !== (int) $order->tenant_id) {
                    throw new RuntimeException(
                        'Order and catalog item belong to different tenants.'
                    );
                }

                // Services do not require inventory or fulfillment requests.
                if (! $catalogItem->track_inventory) {
                    continue;
                }

                $requestedQuantity = (float) $item->quantity;

                if ($requestedQuantity <= 0) {
                    throw new RuntimeException(
                        "Order item {$item->id} has an invalid quantity."
                    );
                }

                // The order row is locked above, serializing fulfillment
                // attempts for this order.
                $request = FulfillmentRequest::query()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('order_id', $order->id)
                    ->where('order_item_id', $item->id)
                    ->lockForUpdate()
                    ->first();

                if (! $request) {
                    $request = FulfillmentRequest::create([
                        'tenant_id' => $order->tenant_id,
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'branch_id' => $order->branch_id,
                        'catalog_item_id' => $catalogItem->id,
                        'requested_quantity' => $requestedQuantity,
                        'available_quantity' => 0,
                        'fulfilled_quantity' => 0,
                        'shortfall_quantity' => $requestedQuantity,
                        'status' => FulfillmentStatus::PENDING,
                    ]);
                }

                if ($request->status === FulfillmentStatus::CANCELLED) {
                    throw new RuntimeException(
                        "Fulfillment request {$request->id} is cancelled."
                    );
                }

                $previouslyFulfilled = (float) $request->fulfilled_quantity;

                // The order item is the source of truth for the total
                // requested quantity.
                $remainingQuantity = max(
                    0,
                    $requestedQuantity - $previouslyFulfilled
                );

                if ($remainingQuantity <= 0.000001) {
                    $request->requested_quantity = $requestedQuantity;
                    $request->fulfilled_quantity = $requestedQuantity;
                    $request->available_quantity = $requestedQuantity;
                    $request->shortfall_quantity = 0;
                    $request->status = FulfillmentStatus::FULFILLED;
                    $request->save();

                    continue;
                }

                $result = $this->inventoryService->sellStock(
                    tenantId: (int) $order->tenant_id,
                    branchId: (int) $order->branch_id,
                    catalogItemId: (int) $catalogItem->id,
                    requestedQuantity: $remainingQuantity,
                    referenceType: Order::class,
                    referenceId: (int) $order->id,
                );

                $cumulativeFulfilled = min(
                    $requestedQuantity,
                    $previouslyFulfilled
                        + (float) $result['fulfilled_quantity']
                );

                $shortfall = max(
                    0,
                    $requestedQuantity - $cumulativeFulfilled
                );

                $request->requested_quantity = $requestedQuantity;
                $request->available_quantity = $cumulativeFulfilled;
                $request->fulfilled_quantity = $cumulativeFulfilled;
                $request->shortfall_quantity = $shortfall;

                $request->status = $shortfall <= 0.000001
                    ? FulfillmentStatus::FULFILLED
                    : (
                        $cumulativeFulfilled > 0
                            ? FulfillmentStatus::PARTIALLY_FULFILLED
                            : FulfillmentStatus::PENDING
                    );

                $request->save();
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