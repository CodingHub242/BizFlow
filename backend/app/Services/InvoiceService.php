<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Invoice;
use App\Models\Branch;
use App\Models\User;
use App\Models\Order;
use App\Models\Customer;
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
                $catalogItem = CatalogItem::query()
                    ->where('tenant_id', $data['tenant_id'])
                    ->whereKey($itemData['catalog_item_id'])
                    ->first();

                if (! $catalogItem) {
                    throw ValidationException::withMessages([
                        'items' => 'The catalog item does not belong to this tenant.',
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
                    'catalog_item_id' => $catalogItem->id,
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

            if ($totalPaid >= (float) $invoice->total) {
                $invoice->payment_status = \App\PaymentStatus::PAID;
                $invoice->status = \App\InvoiceStatus::PAID;
            } elseif ($totalPaid > 0) {
                $invoice->payment_status = \App\PaymentStatus::PARTIALLY_PAID;
                $invoice->status = \App\InvoiceStatus::PARTIALLY_PAID;
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
                $catalogItem = CatalogItem::query()
                    ->where('tenant_id', $invoice->tenant_id)
                    ->whereKey($itemData['catalog_item_id'])
                    ->first();

                if (! $catalogItem) {
                    throw ValidationException::withMessages([
                        'items' => 'The catalog item does not belong to this tenant.',
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
                    'catalog_item_id' => $catalogItem->id,
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

            if (
                $invoice->status === \App\InvoiceStatus::PARTIALLY_PAID ||
                $invoice->payment_status === \App\PaymentStatus::PARTIALLY_PAID
            ) {
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
}