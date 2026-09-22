<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Purchase;
use App\Models\Supplier;
use App\CatalogItemType;
use App\PurchaseStatus;
use App\Services\InventoryService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function create(array $data): Purchase
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
            // Validate supplier belongs to tenant
            // ---------------------------------------------------------

            $supplierExists = Supplier::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($data['supplier_id'])
                ->exists();

            if (!$supplierExists) {
                throw ValidationException::withMessages([
                    'supplier_id' => [
                        'The selected supplier does not belong to this tenant.',
                    ],
                ]);
            }

            // ---------------------------------------------------------
            // Validate creator belongs to tenant
            // ---------------------------------------------------------

            $creatorExists = User::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($data['created_by'])
                ->exists();

            if (!$creatorExists) {
                throw ValidationException::withMessages([
                    'created_by' => [
                        'The selected user does not belong to this tenant.',
                    ],
                ]);
            }

            // ---------------------------------------------------------
            // Validate purchase items
            // ---------------------------------------------------------

            $items = $data['items'] ?? [];

            $subtotal = 0;

            $validatedItems = [];

            foreach ($items as $index => $itemData) {

                $quantity = (float) ($itemData['quantity'] ?? 0);
                $unitPrice = (float) ($itemData['unit_price'] ?? 0);

                // Quantity must be positive.
                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => [
                            'Quantity must be greater than zero.',
                        ],
                    ]);
                }

                // Purchase price cannot be negative.
                if ($unitPrice < 0) {
                    throw ValidationException::withMessages([
                        "items.$index.unit_price" => [
                            'Purchase price cannot be negative.',
                        ],
                    ]);
                }

                // -----------------------------------------------------
                // Catalog item must belong to the tenant
                // -----------------------------------------------------

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

                // -----------------------------------------------------
                // Catalog item must be active
                // -----------------------------------------------------

                if (!$catalogItem->is_active) {
                    throw ValidationException::withMessages([
                        "items.$index.catalog_item_id" => [
                            'The selected catalog item is inactive.',
                        ],
                    ]);
                }

                $discount = (float) ($itemData['discount'] ?? 0);
                $tax = (float) ($itemData['tax'] ?? 0);

                $lineTotal = ($quantity * $unitPrice)
                    - $discount
                    + $tax;

                if ($lineTotal < 0) {
                    throw ValidationException::withMessages([
                        "items.$index" => [
                            'The purchase item total cannot be negative.',
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
            // Purchase totals
            // ---------------------------------------------------------

            $discount = (float) ($data['discount'] ?? 0);
            $tax = (float) ($data['tax'] ?? 0);

            $total = $subtotal - $discount + $tax;

            if ($total < 0) {
                throw ValidationException::withMessages([
                    'total' => [
                        'Purchase total cannot be negative.',
                    ],
                ]);
            }

            // ---------------------------------------------------------
            // Create purchase
            // ---------------------------------------------------------

            $purchase = Purchase::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branch->id,
                'supplier_id' => $data['supplier_id'],
                'created_by' => $data['created_by'],
                'purchase_number' => $data['purchase_number'],
                'status' => $data['status'] ?? 'draft',
                'payment_status' => $data['payment_status'] ?? 'unpaid',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            // ---------------------------------------------------------
            // Create purchase items
            // ---------------------------------------------------------

            foreach ($validatedItems as $item) {
                $purchase->items()->create($item);
            }

            return $purchase->load('items');
        });
    }

    public function receive(Purchase $purchase, array $items): Purchase
    {
        return DB::transaction(function () use ($purchase, $items) {

            $purchase = Purchase::query()
                ->where('tenant_id', $purchase->tenant_id)
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();

            if ($purchase->status === PurchaseStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'purchase' => 'Cancelled purchases cannot be received.',
                ]);
            }

            if (empty($items)) {
                throw ValidationException::withMessages([
                    'items' => 'At least one purchase item is required.',
                ]);
            }

            foreach ($items as $data) {
                $purchaseItem = $purchase->items
                    ->firstWhere('id', $data['purchase_item_id'] ?? null);

                if (!$purchaseItem) {
                    throw ValidationException::withMessages([
                        'purchase_item_id' => 'The purchase item does not belong to this purchase.',
                    ]);
                }

                $quantity = (float) ($data['quantity'] ?? 0);

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Received quantity must be greater than zero.',
                    ]);
                }

                $remainingQuantity =
                    (float) $purchaseItem->quantity -
                    (float) $purchaseItem->received_quantity;

                if ($quantity > $remainingQuantity) {
                    throw ValidationException::withMessages([
                        'quantity' => "Cannot receive {$quantity}. Only {$remainingQuantity} remains to be received.",
                    ]);
                }

                $purchaseItem->increment('received_quantity', $quantity);

                app(InventoryService::class)->receiveStock(
                    tenantId: $purchase->tenant_id,
                    branchId: $purchase->branch_id,
                    catalogItemId: $purchaseItem->catalog_item_id,
                    quantity: $quantity,
                    notes: "Received from purchase {$purchase->purchase_number}",
                    referenceType: Purchase::class,
                    referenceId: $purchase->id,
                );
            }

            $purchase->refresh();
            $purchase->load('items');

            $totalOrdered = $purchase->items->sum(
                fn ($item) => (float) $item->quantity
            );

            $totalReceived = $purchase->items->sum(
                fn ($item) => (float) $item->received_quantity
            );

            if ($totalReceived >= $totalOrdered) {
                $purchase->status = PurchaseStatus::RECEIVED;
            } elseif ($totalReceived > 0) {
                $purchase->status = PurchaseStatus::PARTIALLY_RECEIVED;
            }

            $purchase->save();

            return $purchase;
        });
    }
}