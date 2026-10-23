<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Invoice;
use App\Models\Branch;
use App\Models\User;
use App\Models\Order;
use App\Models\Customer;
use App\Models\OrderItem;
use Illuminate\Database\QueryException;
use App\Models\InvoicePaymentReversal;
use App\Models\InvoicePayment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function create(array $data): Invoice
    {
        $branch = Branch::query()
            ->where('tenant_id', $data['tenant_id'])
            ->whereKey($data['branch_id'])
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages([
                'branch_id' => 'The branch does not belong to this tenant.',
            ]);
        }

        $creator = User::query()
            ->where('tenant_id', $data['tenant_id'])
            ->whereKey($data['created_by'])
            ->first();

        if (! $creator) {
            throw ValidationException::withMessages([
                'created_by' => 'The creator does not belong to this tenant.',
            ]);
        }

        if (! empty($data['customer_id'])) {
            $customer = Customer::query()
                ->where('tenant_id', $data['tenant_id'])
                ->whereKey($data['customer_id'])
                ->first();

            if (! $customer) {
                throw ValidationException::withMessages([
                    'customer_id' => 'The customer does not belong to this tenant.',
                ]);
            }
        }

        if (! empty($data['order_id'])) {
            $order = Order::query()
                ->where('tenant_id', $data['tenant_id'])
                ->whereKey($data['order_id'])
                ->first();

            if (! $order) {
                throw ValidationException::withMessages([
                    'order_id' => 'The order does not belong to this tenant.',
                ]);
            }
        }

        return DB::transaction(function () use ($data) {
            try{
                $invoice = Invoice::create([
                    'tenant_id' => $data['tenant_id'],
                    'branch_id' => $data['branch_id'],
                    'customer_id' => $data['customer_id'] ?? null,
                    'order_id' => $data['order_id'] ?? null,
                    'created_by' => $data['created_by'],
                    'invoice_number' => $data['invoice_number'],
                    'status' => 'draft',
                    'payment_status' => 'unpaid',
                    'subtotal' => 0,
                    'discount' => 0,
                    'issued_at' => $data['issued_at'] ?? null,
                    'due_at' => $data['due_at'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'tax' => 0,
                    'total' => 0,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw ValidationException::withMessages([
                    'invoice_number' => 'This invoice number has already been used.',
                ]);
            }

            $subtotal = 0;
            $discount = 0;
            $tax = 0;

            foreach ($data['items'] as $itemData) {
                $catalogItemId = $itemData['catalog_item_id'] ?? null;
                $catalogItem = null;
                if ($catalogItemId !== null && $catalogItemId !== '') {
                $catalogItem = CatalogItem::query()
                    ->where('tenant_id', $data['tenant_id'])
                    ->whereKey($catalogItemId)
                    ->first();

                 if (! $catalogItem) {
                        throw ValidationException::withMessages([
                            'items.catalog_item_id' => [
                                'The selected catalog item does not belong to this tenant.',
                            ],
                        ]);
                    }
                }

               $description = trim((string) ($itemData['description'] ?? ''));

                if ($description === '' && $catalogItem !== null) {
                    $description = $catalogItem->name;
                }

                if ($description === '') {
                    throw ValidationException::withMessages([
                        'items' => 'Every invoice line must have a catalog item or a description.',
                    ]);
                }

                $quantity = (float) $itemData['quantity'];
                $unitPrice = (float) $itemData['unit_price'];
                $itemDiscount = (float) ($itemData['discount'] ?? 0);
                $itemTax = (float) ($itemData['tax'] ?? 0);

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Item quantity must be greater than zero.',
                    ]);
                }

                if ($unitPrice < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Item unit price cannot be negative.',
                    ]);
                }

                if ($itemDiscount < 0 || $itemTax < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Item discount and tax cannot be negative.',
                    ]);
                }

                $lineSubtotal = $quantity * $unitPrice;
                $lineTotal = $lineSubtotal - $itemDiscount + $itemTax;

                if ($lineTotal < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Item total cannot be negative.',
                    ]);
                }

                $invoice->items()->create([
                    'tenant_id' => $data['tenant_id'],
                    'catalog_item_id' => $catalogItem?->id,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $itemDiscount,
                    'tax' => $itemTax,
                    'line_total' => $lineTotal,
                ]);

                $subtotal += $lineSubtotal;
                $discount += $itemDiscount;
                $tax += $itemTax;
            }

            $total = $subtotal - $discount + $tax;

            $invoice->update([
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
            ]);

            return $invoice->fresh();
        });
    }

    public function createFromOrder(Order $order): Invoice
    {
        return DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()
                ->where('tenant_id', $order->tenant_id)
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency: an order can have only one linked invoice.
            $existingInvoice = Invoice::query()
                ->where('tenant_id', $lockedOrder->tenant_id)
                ->where('order_id', $lockedOrder->id)
                ->first();

            if ($existingInvoice) {
                return $existingInvoice;
            }

            $items = $lockedOrder->items()
                ->with('catalogItem')
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'An order must contain at least one item before an invoice can be created.',
                ]);
            }

            $subtotal = 0.0;
            $itemDiscount = 0.0;
            $itemTax = 0.0;

            foreach ($items as $item) {
                if (! $item->catalogItem ||
                    (int) $item->catalogItem->tenant_id !== (int) $lockedOrder->tenant_id) {
                    throw ValidationException::withMessages([
                        'items' => 'An order contains an unavailable catalog item.',
                    ]);
                }

                $subtotal += (float) $item->quantity * (float) $item->unit_price;
                $itemDiscount += (float) $item->discount;
                $itemTax += (float) $item->tax;
            }

            $discount = $itemDiscount + (float) $lockedOrder->discount;
            $tax = $itemTax + (float) $lockedOrder->tax;

           try {
                $invoice = Invoice::query()->create([
                    'tenant_id' => $lockedOrder->tenant_id,
                    'branch_id' => $lockedOrder->branch_id,
                    'customer_id' => $lockedOrder->customer_id,
                    'order_id' => $lockedOrder->id,
                    'created_by' => $lockedOrder->created_by,
                    'invoice_number' => 'INV-' . $lockedOrder->order_number,
                    'status' => 'draft',
                    'payment_status' => 'unpaid',
                    'subtotal' => round($subtotal, 2),
                    'discount' => round($discount, 2),
                    'tax' => round($tax, 2),
                    'total' => round($subtotal - $discount + $tax, 2),
                    'notes' => $lockedOrder->notes,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                throw ValidationException::withMessages([
                    'invoice_number' => 'This invoice number has already been used.',
                ]);
            }

            foreach ($items as $item) {
                $invoice->items()->create([
                    'tenant_id' => $lockedOrder->tenant_id,
                    'catalog_item_id' => $item->catalog_item_id,
                    'description' => $item->catalogItem->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount' => $item->discount,
                    'tax' => $item->tax,
                    'line_total' => $item->line_total,
                ]);
            }

            return $invoice->fresh()->load('items');
        });
    }


    public function syncDraftFromOrder(Order $order): Invoice
    {
        return DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()
                ->where('tenant_id', $order->tenant_id)
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Support legacy orders that do not yet have a linked invoice.
            $invoice = Invoice::query()
                ->where('tenant_id', $lockedOrder->tenant_id)
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->first();

            if (! $invoice) {
                $invoice = $this->createFromOrder($lockedOrder);
            }

            // Never overwrite an invoice that has left the draft state.
            if (
                $invoice->status !== \App\InvoiceStatus::DRAFT
                || $invoice->payment_status !== \App\PaymentStatus::UNPAID
            ) {
                throw ValidationException::withMessages([
                    'invoice' => [
                        'The linked invoice is no longer an editable draft. '
                        . 'The order cannot be updated.',
                    ],
                ]);
            }

            $items = $lockedOrder->items()
                ->with('catalogItem')
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'An order must contain at least one item.',
                ]);
            }

            $subtotal = 0.0;
            $itemDiscount = 0.0;
            $itemTax = 0.0;
            $invoiceItems = [];

            foreach ($items as $item) {
                $catalogItem = $item->catalogItem;

                if (
                    ! $catalogItem
                    || (int) $catalogItem->tenant_id
                        !== (int) $lockedOrder->tenant_id
                ) {
                    throw ValidationException::withMessages([
                        'items' => 'An order contains an unavailable catalog item.',
                    ]);
                }

                $quantity = (float) $item->quantity;
                $unitPrice = (float) $item->unit_price;
                $discount = (float) $item->discount;
                $tax = (float) $item->tax;

                if (
                    $quantity <= 0
                    || $unitPrice < 0
                    || $discount < 0
                    || $tax < 0
                ) {
                    throw ValidationException::withMessages([
                        'items' => 'An order contains invalid item values.',
                    ]);
                }

                $lineTotal = ($quantity * $unitPrice) - $discount + $tax;

                if ($lineTotal < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'An order item total cannot be negative.',
                    ]);
                }

                $subtotal += $quantity * $unitPrice;
                $itemDiscount += $discount;
                $itemTax += $tax;

                $invoiceItems[] = [
                    'tenant_id' => $lockedOrder->tenant_id,
                    'catalog_item_id' => $item->catalog_item_id,
                    'description' => $catalogItem->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'tax' => $tax,
                    'line_total' => $lineTotal,
                ];
            }

            $discount = $itemDiscount + (float) $lockedOrder->discount;
            $tax = $itemTax + (float) $lockedOrder->tax;
            $total = $subtotal - $discount + $tax;

            if ($total < 0) {
                throw ValidationException::withMessages([
                    'total' => 'The synchronized invoice total cannot be negative.',
                ]);
            }

            $invoice->update([
                'branch_id' => $lockedOrder->branch_id,
                'customer_id' => $lockedOrder->customer_id,
                'created_by' => $lockedOrder->created_by,
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'tax' => round($tax, 2),
                'total' => round($total, 2),
                'notes' => $lockedOrder->notes,
            ]);

            // Replace the draft invoice lines atomically.
            $invoice->items()->delete();

            foreach ($invoiceItems as $invoiceItem) {
                $invoice->items()->create($invoiceItem);
            }

            return $invoice->fresh()->load('items');
        });
    }


    public function issue(Invoice $invoice): Invoice
    {
        $invoice->refresh();

        if ($invoice->status !== \App\InvoiceStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Only draft invoices can be issued.',
            ]);
        }

        $invoice->status = \App\InvoiceStatus::ISSUED;
        $invoice->issued_at = now();
        $invoice->save();

        return $invoice->fresh();
    }

    public function recordPayment(Invoice $invoice, array $data): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::query()
                ->where('tenant_id', $invoice->tenant_id)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->status === \App\InvoiceStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'invoice' => 'Cancelled invoices cannot receive payments.',
                ]);
            }

            if ($invoice->status === \App\InvoiceStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'invoice' => 'Draft invoices cannot receive payments.',
                ]);
            }


            $recordedBy = User::query()
                ->where('tenant_id', $invoice->tenant_id)
                ->whereKey($data['recorded_by'])
                ->first();

            if (! $recordedBy) {
                throw ValidationException::withMessages([
                    'recorded_by' => 'The recording user does not belong to this tenant.',
                ]);
            }

            $paidAmount = (float) $invoice->payments()->sum('amount');
            $outstandingBalance = (float) $invoice->total - $paidAmount;
            $amount = (float) $data['amount'];

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount must be greater than zero.',
                ]);
            }

            if ($amount > $outstandingBalance) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment cannot exceed the invoice outstanding balance.',
                ]);
            }

            try {
                $payment = InvoicePayment::create([
                    'tenant_id' => $invoice->tenant_id,
                    'invoice_id' => $invoice->id,
                    'recorded_by' => $data['recorded_by'],
                    'amount' => $amount,
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'paid_at' => $data['paid_at'],
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw ValidationException::withMessages([
                    'reference' => 'This payment reference has already been used.',
                ]);
            }

            $totalPaid = $paidAmount + $amount;

            // if ($totalPaid >= (float) $invoice->total) {
            //     $invoice->payment_status = \App\PaymentStatus::PAID;
            //     $invoice->status = \App\InvoiceStatus::PAID;
            // } elseif ($totalPaid > 0) {
            //     $invoice->payment_status = \App\PaymentStatus::PARTIALLY_PAID;
            //     $invoice->status = \App\InvoiceStatus::PARTIALLY_PAID;
            // }
            if ($totalPaid >= (float) $invoice->total) {
                $invoice->payment_status = \App\PaymentStatus::PAID;
            } elseif ($totalPaid > 0) {
                $invoice->payment_status = \App\PaymentStatus::PARTIALLY_PAID;
            } else {
                $invoice->payment_status = \App\PaymentStatus::UNPAID;
            }

            $invoice->save();

            return $payment;
        });
    }

    public function markOverdue(Invoice $invoice): Invoice
    {
        $invoice->refresh();

        if ($invoice->due_at === null) {
            return $invoice;
        }

        if ($invoice->due_at->isFuture()) {
            return $invoice;
        }

        if ($invoice->status === \App\InvoiceStatus::DRAFT) {
            return $invoice;
        }

        if ($invoice->status === \App\InvoiceStatus::PAID) {
            return $invoice;
        }

        if ($invoice->status === \App\InvoiceStatus::CANCELLED) {
            return $invoice;
        }

        if ($invoice->payment_status === \App\PaymentStatus::PAID) {
            return $invoice;
        }

        $invoice->status = \App\InvoiceStatus::OVERDUE;
        $invoice->save();

        return $invoice->fresh();
    }

    public function update(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::query()
                ->where('tenant_id', $invoice->tenant_id)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->status !== \App\InvoiceStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Only draft invoices can be updated.',
                ]);
            }

            if (array_key_exists('customer_id', $data) && ! empty($data['customer_id'])) {
                $customer = Customer::query()
                    ->where('tenant_id', $invoice->tenant_id)
                    ->whereKey($data['customer_id'])
                    ->first();

                if (! $customer) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'The customer does not belong to this tenant.',
                    ]);
                }
            }

            if (array_key_exists('branch_id', $data)) {
                $branch = Branch::query()
                    ->where('tenant_id', $invoice->tenant_id)
                    ->whereKey($data['branch_id'])
                    ->first();

                if (! $branch) {
                    throw ValidationException::withMessages([
                        'branch_id' => 'The branch does not belong to this tenant.',
                    ]);
                }
            }

            $items = $data['items'] ?? [];

            if (empty($items)) {
                throw ValidationException::withMessages([
                    'items' => 'An invoice must contain at least one item.',
                ]);
            }

            $subtotal = 0;
            $discount = 0;
            $tax = 0;

            $validatedItems = [];

            foreach ($items as $itemData) {
                $catalogItemId = $itemData['catalog_item_id'] ?? null;
                $catalogItem = null;
                if ($catalogItemId !== null && $catalogItemId !== '') {
                    $catalogItem = CatalogItem::query()
                        ->where('tenant_id', $invoice->tenant_id)
                        ->whereKey($catalogItemId)
                        ->first();

                    if (! $catalogItem) {
                        throw ValidationException::withMessages([
                            'items.catalog_item_id' => [
                                'The catalog item does not belong to this tenant.',
                            ],
                        ]);
                    }
                }

                 $description = trim((string) ($itemData['description'] ?? ''));

                if ($description === '' && $catalogItem !== null) {
                    $description = $catalogItem->name;
                }

                if ($description === '') {
                    throw ValidationException::withMessages([
                        'items' => 'Every invoice line must have a catalog item or a description.',
                    ]);
                }

                $quantity = (float) $itemData['quantity'];
                $unitPrice = (float) $itemData['unit_price'];
                $itemDiscount = (float) ($itemData['discount'] ?? 0);
                $itemTax = (float) ($itemData['tax'] ?? 0);

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Item quantity must be greater than zero.',
                    ]);
                }

                if ($unitPrice < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Item unit price cannot be negative.',
                    ]);
                }

                if ($itemDiscount < 0 || $itemTax < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Item discount and tax cannot be negative.',
                    ]);
                }

                $lineSubtotal = $quantity * $unitPrice;
                $lineTotal = $lineSubtotal - $itemDiscount + $itemTax;

                if ($lineTotal < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Item total cannot be negative.',
                    ]);
                }

                $validatedItems[] = [
                    'tenant_id' => $invoice->tenant_id,
                    'catalog_item_id' => $catalogItem?->id,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $itemDiscount,
                    'tax' => $itemTax,
                    'line_total' => $lineTotal,
                ];

                $subtotal += $lineSubtotal;
                $discount += $itemDiscount;
                $tax += $itemTax;
            }

            $total = $subtotal - $discount + $tax;

            $invoice->update([
                'branch_id' => $data['branch_id'] ?? $invoice->branch_id,
                'customer_id' => array_key_exists('customer_id', $data)
                    ? $data['customer_id']
                    : $invoice->customer_id,
                'due_at' => array_key_exists('due_at', $data)
                    ? $data['due_at']
                    : $invoice->due_at,
                'notes' => array_key_exists('notes', $data)
                    ? $data['notes']
                    : $invoice->notes,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
            ]);

            $invoice->items()->delete();

            foreach ($validatedItems as $item) {
                $invoice->items()->create($item);
            }

            return $invoice->fresh();
        });
    }

    public function cancel(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()
                ->where('tenant_id', $invoice->tenant_id)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            // if (
            //     $invoice->status === \App\InvoiceStatus::PARTIALLY_PAID ||
            //     $invoice->payment_status === \App\PaymentStatus::PARTIALLY_PAID
            // ) {
            //     throw ValidationException::withMessages([
            //         'status' => 'A partially paid invoice cannot be cancelled.',
            //     ]);
            // }
            if ($invoice->payment_status === \App\PaymentStatus::PARTIALLY_PAID) {
                throw ValidationException::withMessages([
                    'status' => 'A partially paid invoice cannot be cancelled.',
                ]);
            }

            if ($invoice->payment_status === \App\PaymentStatus::PAID) {
                throw ValidationException::withMessages([
                    'status' => 'A paid invoice cannot be cancelled.',
                ]);
            }

            if ($invoice->status === \App\InvoiceStatus::OVERDUE) {
                throw ValidationException::withMessages([
                    'status' => 'An overdue invoice cannot be cancelled.',
                ]);
            }

            if ($invoice->status === \App\InvoiceStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => 'Invoice is already cancelled.',
                ]);
            }

            $invoice->status = \App\InvoiceStatus::CANCELLED;
            $invoice->save();

            return $invoice->fresh();
        });
    }

    public function reversePayment(
        InvoicePayment $payment,
        int $recordedBy,
        string $reason,
        ?string $notes = null
        ): InvoicePaymentReversal {
        return DB::transaction(function () use (
            $payment,
            $recordedBy,
            $reason,
            $notes
        ) {
            $payment = InvoicePayment::query()
                ->where('tenant_id', $payment->tenant_id)
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $invoice = Invoice::query()
                ->where('tenant_id', $payment->tenant_id)
                ->whereKey($payment->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            $user = User::query()
                ->where('tenant_id', $payment->tenant_id)
                ->whereKey($recordedBy)
                ->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'recorded_by' => 'The recording user does not belong to this tenant.',
                ]);
            }

            if ($payment->reversal()->exists()) {
                throw ValidationException::withMessages([
                    'payment' => 'This payment has already been reversed.',
                ]);
            }

            if (trim($reason) === '') {
                throw ValidationException::withMessages([
                    'reason' => 'A reversal reason is required.',
                ]);
            }

            if (mb_strlen($reason) > 255) {
                throw ValidationException::withMessages([
                    'reason' => 'The reversal reason cannot exceed 255 characters.',
                ]);
            }

            if ($payment->amount <= 0) {
                throw ValidationException::withMessages([
                    'payment' => 'Payment amount must be greater than zero.',
                ]);
            }

            $reversal = InvoicePaymentReversal::create([
                'tenant_id' => $payment->tenant_id,
                'invoice_payment_id' => $payment->id,
                'recorded_by' => $recordedBy,
                'amount' => $payment->amount,
                'reason' => $reason,
                'notes' => $notes,
                'reversed_at' => now(),
            ]);

            $totalPaid = (float) $invoice->payments()
                ->whereDoesntHave('reversal')
                ->sum('amount');

            if ($totalPaid <= 0) {
                $invoice->payment_status = \App\PaymentStatus::UNPAID;

                // if ($invoice->status === \App\InvoiceStatus::PAID) {
                //     $invoice->status = \App\InvoiceStatus::ISSUED;
                // }
            } elseif ($totalPaid >= (float) $invoice->total) {
                $invoice->payment_status = \App\PaymentStatus::PAID;
            } else {
                $invoice->payment_status = \App\PaymentStatus::PARTIALLY_PAID;

                // if (
                //     $invoice->status === \App\InvoiceStatus::PAID ||
                //     $invoice->status === \App\InvoiceStatus::PARTIALLY_PAID
                // ) {
                //     $invoice->status = \App\InvoiceStatus::PARTIALLY_PAID;
                // }
            }

            $invoice->save();

            return $reversal;
        });
    }
}