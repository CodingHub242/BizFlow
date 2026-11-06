<?php

namespace App\Http\Resources;

use App\Http\Resources\CustomerResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EngageContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,

            'name' => $this->name,
            'phone' => $this->phone,
            'normalized_phone' => $this->normalized_phone,
            'email' => $this->email,
            'company_name' => $this->company_name,

            'source' => $this->source,
            'lifecycle_status' => $this->lifecycle_status,
            'status' => $this->status,
            'notes' => $this->notes,

            'first_contacted_at' => $this->first_contacted_at,
            'last_contacted_at' => $this->last_contacted_at,

            'customer' => $this->whenLoaded(
                'customer',
                fn () => $this->customer
                    ? new CustomerResource($this->customer)
                    : null
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}