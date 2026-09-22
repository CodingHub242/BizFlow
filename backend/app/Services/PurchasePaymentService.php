<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\PurchasePaymentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\UniqueConstraintViolationException;

class PurchasePaymentService
{
    public function create(array $data): PurchasePayment
    {
        return DB::transaction(function () use ($data) {
            $purchase = Purchase::query()
                ->where('tenant_id', $data['tenant_id'])
                ->whereKey($data['purchase_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $recordedBy = \App\Models\User::query()
                ->where('tenant_id', $purchase->tenant_id)
                ->whereKey($data['recorded_by'])
                ->first();

            if (! $recordedBy) {
                throw ValidationException::withMessages([
                    'recorded_by' => 'The recording user does not belong to this tenant.',
                ]);
            }

            $paidAmount = (float) $purchase->payments()->sum('amount');

            $outstandingBalance =
                (float) $purchase->total - $paidAmount;

            $amount = (float) $data['amount'];

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount must be greater than zero.',
                ]);
            }

            if ($amount > $outstandingBalance) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment cannot exceed the purchase outstanding balance.',
                ]);
            }

            try{
                $payment = PurchasePayment::create([
                    'tenant_id' => $purchase->tenant_id,
                    'purchase_id' => $purchase->id,
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

            if ($totalPaid >= (float) $purchase->total) {
                $purchase->payment_status = PurchasePaymentStatus::PAID;
            } elseif ($totalPaid > 0) {
                $purchase->payment_status = PurchasePaymentStatus::PARTIALLY_PAID;
            } else {
                $purchase->payment_status = PurchasePaymentStatus::UNPAID;
            }

            $purchase->save();

            return $payment;
        });
    }
}