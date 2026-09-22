<?php

namespace App\Services;

use App\PaymentStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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