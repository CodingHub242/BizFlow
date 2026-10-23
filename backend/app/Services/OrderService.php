<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Order;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function create(array $data): Order
    {
        $tenantId = $data['tenant_id'];

        return DB::transaction(function () use ($data, $tenantId) {

            // ---------------------------------------------------------
            // Validate branch belongs to tenant
            // ---------------------------------------------------------

           $branchId = $data['branch_id'] ?? null;

            if ($branchId !== null) {
                $branch = Branch::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($branchId)
                    ->firstOrFail();

                $branchId = $branch->id;
            }

            // ---------------------------------------------------------
            // Validate customer belongs to tenant
            // ---------------------------------------------------------

            $customerId = $data['customer_id'] ?? null;

            if ($customerId !== null) {
                $customerExists = Customer::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($customerId)
                    ->exists();

                if (!$customerExists) {
                    throw ValidationException::withMessages([
                        'customer_id' => [
                            'The selected customer does not belong to this tenant.',
                        ],
                    ]);
                }
            }

            // ---------------------------------------------------------
            // Validate order items
            // ---------------------------------------------------------

            $items = $data['items'] ?? [];

            if (empty($items)) {
                $items = [];
            }

            $subtotal = 0;

            $validatedItems = [];

            foreach ($items as $index => $itemData) {

                $quantity = (float) ($itemData['quantity'] ?? 0);

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => [
                            'Quantity must be greater than zero.',
                        ],
                    ]);
                }

                $catalogItem = CatalogItem::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($itemData['catalog_item_id'])
                    ->first();

                if (!$catalogItem) {
                    throw ValidationException::withMessages([
                        "items.$index.catalog_item_id" => [
                            'The selected catalog item does not belong to this tenant.',
                        ],
                    ]);
                }

                if (!$catalogItem->is_active) {
                    throw ValidationException::withMessages([
                        "items.$index.catalog_item_id" => [
                            'The selected catalog item is inactive.',
                        ],
                    ]);
                }

                $unitPrice = (float) $catalogItem->selling_price;

                $discount = (float) ($itemData['discount'] ?? 0);
                $tax = (float) ($itemData['tax'] ?? 0);

                $lineTotal = ($quantity * $unitPrice) - $discount + $tax;

                if ($lineTotal < 0) {
                    throw ValidationException::withMessages([
                        "items.$index" => [
                            'The item total cannot be negative.',
                        ],
                    ]);
                }

                $subtotal += $lineTotal;

                $validatedItems[] = [
                    'tenant_id' => $tenantId,
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'tax' => $tax,
                    'line_total' => $lineTotal,
                ];
            }

            // ---------------------------------------------------------
            // Order totals
            // ---------------------------------------------------------

            $orderDiscount = (float) ($data['discount'] ?? 0);
            $orderTax = (float) ($data['tax'] ?? 0);

            $total = $subtotal - $orderDiscount + $orderTax;

            if ($total < 0) {
                throw ValidationException::withMessages([
                    'total' => [
                        'Order total cannot be negative.',
                    ],
                ]);
            }

            // ---------------------------------------------------------
            // Create order
            // ---------------------------------------------------------

            $order = Order::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'created_by' => $data['created_by'],
                'customer_id' => $customerId,
                'order_number' => $data['order_number'],
                'status' => $data['status'] ?? 'draft',
                'payment_status' => $data['payment_status'] ?? 'unpaid',
                'subtotal' => $subtotal,
                'discount' => $orderDiscount,
                'tax' => $orderTax,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            // ---------------------------------------------------------
            // Create order items
            // ---------------------------------------------------------

            foreach ($validatedItems as $item) {
                $order->items()->create($item);
            }

            app(InvoiceService::class)->createFromOrder($order);

            return $order->fresh()->load('items');

            //return $order->load('items');
        });
    }

    public function update(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data) {
            $order = Order::query()
                ->where('tenant_id', $order->tenant_id)
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->assertOrdinaryModificationAllowed($order);

            $tenantId = $order->tenant_id;

            $branchId = $data['branch_id'] ?? null;

            if ($branchId !== null) {
                $branch = Branch::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($branchId)
                    ->firstOrFail();

                $branchId = $branch->id;
            }

            $customerId = $data['customer_id'] ?? null;

            if ($customerId !== null && !Customer::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($customerId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'customer_id' => [
                        'The selected customer does not belong to this tenant.',
                    ],
                ]);
            }

            $items = $data['items'];
            $validatedItems = [];
            $subtotal = 0;

            foreach ($items as $index => $itemData) {
                $quantity = (float) $itemData['quantity'];

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => [
                            'Quantity must be greater than zero.',
                        ],
                    ]);
                }

                $catalogItem = CatalogItem::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($itemData['catalog_item_id'])
                    ->first();

                if (!$catalogItem || !$catalogItem->is_active) {
                    throw ValidationException::withMessages([
                        "items.$index.catalog_item_id" => [
                            'The selected catalog item is unavailable.',
                        ],
                    ]);
                }

                $unitPrice = (float) $catalogItem->selling_price;
                $discount = (float) ($itemData['discount'] ?? 0);
                $tax = (float) ($itemData['tax'] ?? 0);
                $lineTotal = ($quantity * $unitPrice) - $discount + $tax;

                if ($discount < 0 || $tax < 0 || $lineTotal < 0) {
                    throw ValidationException::withMessages([
                        "items.$index" => [
                            'The item discount, tax, or total is invalid.',
                        ],
                    ]);
                }

                $subtotal += $lineTotal;

                $validatedItems[] = [
                    'tenant_id' => $tenantId,
                    'catalog_item_id' => $catalogItem->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'tax' => $tax,
                    'line_total' => $lineTotal,
                ];
            }

            $discount = (float) ($data['discount'] ?? 0);
            $tax = (float) ($data['tax'] ?? 0);
            $total = $subtotal - $discount + $tax;

            if ($discount < 0 || $tax < 0 || $total < 0) {
                throw ValidationException::withMessages([
                    'total' => ['The order totals are invalid.'],
                ]);
            }

            $order->update([
                'branch_id' => $branchId,
                'customer_id' => $customerId,
                'order_number' => $data['order_number'],
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            // Safe only because the guard rejects orders with fulfillment records.
            $order->items()->delete();

            foreach ($validatedItems as $item) {
                $order->items()->create($item);
            }

            // Keep the linked draft invoice synchronized with the edited order.
            app(InvoiceService::class)->syncDraftFromOrder($order);

            return $order->fresh()->load([
                'items.catalogItem',
                'customer',
                'branch',
                'payments',
            ]);
        });
    }

    public function delete(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = Order::query()
                ->where('tenant_id', $order->tenant_id)
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->assertOrdinaryModificationAllowed($order);

            if ($order->payments()->exists()) {
                throw ValidationException::withMessages([
                    'order' => [
                        'An order with recorded payments cannot be deleted.',
                    ],
                ]);
            }

            if ($order->fulfillmentRequests()->exists()) {
                throw ValidationException::withMessages([
                    'order' => [
                        'An order with fulfillment records cannot be deleted.',
                    ],
                ]);
            }

            $order->delete();
        });
    }

    
private function assertOrdinaryModificationAllowed(Order $order): void
{
    $status = $order->status instanceof \BackedEnum
        ? $order->status->value
        : $order->status;

    $paymentStatus = $order->payment_status instanceof \BackedEnum
        ? $order->payment_status->value
        : $order->payment_status;

    if (
        $status !== \App\OrderStatus::DRAFT->value
        || $paymentStatus !== \App\PaymentStatus::UNPAID->value
    ) {
        throw ValidationException::withMessages([
            'order' => [
                'Only draft, unpaid orders can be edited or deleted.',
            ],
        ]);
    }

    if ($order->fulfillmentRequests()->exists()) {
        throw ValidationException::withMessages([
            'order' => [
                'An order with fulfillment records cannot be edited. '
                . 'Cancel or resolve its fulfillment workflow first.',
            ],
        ]);
    }
}

}