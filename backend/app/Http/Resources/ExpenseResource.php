<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'category_id' => $this->category_id,
            'created_by' => $this->created_by,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method instanceof \BackedEnum
                ? $this->payment_method->value
                : $this->payment_method,
            'reference' => $this->reference,
            'expense_date' => $this->expense_date,
            'description' => $this->description,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}