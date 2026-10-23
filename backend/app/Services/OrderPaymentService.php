<?php

namespace App\Services;

use App\PaymentStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use App\InvoiceStatus;
use App\Models\Invoice;
use App\InvoicePaymentMethod;
use App\Models\InvoicePayment;
use Illuminate\Validation\ValidationException;

class OrderPaymentService
{
    public function create(array $data): OrderPayment
    {
        return DB::transaction(function () use ($data) {
            $order = Order::query()
                ->where('tenant_id', $data['tenant_id'])
                ->whereKey($data['order_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $recordedBy = User::query()
                ->where('tenant_id', $order->tenant_id)
                ->whereKey($data['recorded_by'])
                ->first();

            if (! $recordedBy) {
                throw ValidationException::withMessages([
                    'recorded_by' => 'The recording user does not belong to this tenant.',
                ]);
            }

            $paidAmount = (float) $order->payments()->sum('amount');

            $outstandingBalance =
                (float) $order->total - $paidAmount;

            $amount = (float) $data['amount'];

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount must be greater than zero.',
                ]);
            }

            if ($amount > $outstandingBalance) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment cannot exceed the order outstanding balance.',
                ]);
            }

            try {
                $payment = OrderPayment::create([
                    'tenant_id' => $order->tenant_id,
                    'order_id' => $order->id,
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

            $invoice = Invoice::query()
                ->where('tenant_id', $order->tenant_id)
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

                //allow draft to still be paid
          //  if ($invoice && $invoice->status !== InvoiceStatus::DRAFT) {
                if ($invoice->status !== InvoiceStatus::ISSUED
                    && $invoice->status !== InvoiceStatus::OVERDUE && $invoice->status !== InvoiceStatus::DRAFT) {
                    throw ValidationException::withMessages([
                        'invoice' => 'Payments cannot be synchronized to this invoice status.',
                    ]);
                }

                $method = match ($payment->method) {
                    \App\OrderPaymentMethod::CASH => InvoicePaymentMethod::CASH,
                    \App\OrderPaymentMethod::BANK_TRANSFER => InvoicePaymentMethod::BANK_TRANSFER,
                    \App\OrderPaymentMethod::MOBILE_MONEY => InvoicePaymentMethod::MOBILE_MONEY,
                    \App\OrderPaymentMethod::CARD => InvoicePaymentMethod::CARD,
                    \App\OrderPaymentMethod::OTHER => InvoicePaymentMethod::OTHER,
                };

                $paidAmount = (float) $invoice->payments()
                    ->whereDoesntHave('reversal')
                    ->sum('amount');

                if ((float) $payment->amount > (float) $invoice->total - $paidAmount) {
                    throw ValidationException::withMessages([
                        'amount' => 'Payment exceeds the invoice outstanding balance.',
                    ]);
                }

                InvoicePayment::create([
                    'tenant_id' => $invoice->tenant_id,
                    'invoice_id' => $invoice->id,
                    'order_payment_id' => $payment->id,
                    'recorded_by' => $payment->recorded_by,
                    'amount' => $payment->amount,
                    'method' => $method,
                    'reference' => null,
                    'notes' => trim(
                        'Synchronized from order payment #' . $payment->id
                        . ($payment->reference
                            ? '; Original reference: ' . $payment->reference
                            : '')
                        . ($payment->notes ? '; ' . $payment->notes : '')
                    ),
                    'paid_at' => $payment->paid_at,
                ]);

                $newPaidAmount = $paidAmount + (float) $payment->amount;

                $invoice->payment_status = match (true) {
                    $newPaidAmount <= 0 => \App\PaymentStatus::UNPAID,
                    $newPaidAmount >= (float) $invoice->total => \App\PaymentStatus::PAID,
                    default => \App\PaymentStatus::PARTIALLY_PAID,
                };

                $invoice->save();
           // }

            $totalPaid = $paidAmount + $amount;

            if ($totalPaid >= (float) $order->total) {
                $order->payment_status = PaymentStatus::PAID;
            } elseif ($totalPaid > 0) {
                $order->payment_status = PaymentStatus::PARTIALLY_PAID;
            } else {
                $order->payment_status = PaymentStatus::UNPAID;
            }

            $order->save();

            return $payment;
        });
    }
}