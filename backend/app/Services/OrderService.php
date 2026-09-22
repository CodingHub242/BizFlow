<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Order;
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

            $branch = Branch::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($data['branch_id']);

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
                'branch_id' => $branch->id,
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

            return $order->load('items');
        });
    }
}