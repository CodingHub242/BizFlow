<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'catalog_item_id' => $this->catalog_item_id,
            'quantity' => $this->quantity,
            'reorder_level' => $this->reorder_level,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}