<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'tax' => $this->tax,
            'total' => $this->total,
            'issued_at' => $this->issued_at,
            'due_at' => $this->due_at,
            'notes' => $this->notes,

            'customer_id' => $this->customer_id,
            'branch_id' => $this->branch_id,
            'order_id' => $this->order_id,

            'items' => InvoiceItemResource::collection(
                $this->whenLoaded('items')
            ),

            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'company_name' => $this->customer->company_name,
            ]),

            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ]),

            'net_paid' => $this->whenLoaded('payments', function () {
                return (float) $this->payments->sum(function ($payment) {
                    $reversed = (float) ($payment->reversal?->amount ?? 0);

                    return max(0, (float) $payment->amount - $reversed);
                });
            }),


            'credit_applied' => $this->whenLoaded(
                'creditMemoApplications',
                fn () => (float) $this->creditMemoApplications->sum('amount_applied')
            ),

            'balance_due' => $this->when(
                $this->relationLoaded('payments')
                    && $this->relationLoaded('creditMemoApplications'),
                function () {
                    $paid = $this->payments->sum(function ($payment) {
                        return max(
                            0,
                            (float) $payment->amount
                                - (float) ($payment->reversal?->amount ?? 0)
                        );
                    });

                    $credits = (float) $this->creditMemoApplications->sum('amount_applied');

                    return max(0, (float) $this->total - $paid - $credits);
                }
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}