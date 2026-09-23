<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'sku' => $this->sku,
            'description' => $this->description,
            'unit' => $this->unit,
            'cost_price' => $this->cost_price,
            'selling_price' => $this->selling_price,
            'tax_rate' => $this->tax_rate,
            'track_inventory' => $this->track_inventory,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}