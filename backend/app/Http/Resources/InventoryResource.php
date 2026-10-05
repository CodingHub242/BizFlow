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

            'branch' => $this->whenLoaded('branch', function () {
                return [
                    'id' => $this->branch->id,
                    'name' => $this->branch->name,
                    'code' => $this->branch->code,
                ];
            }),

            'catalog_item' => $this->whenLoaded('catalogItem', function () {
                return [
                    'id' => $this->catalogItem->id,
                    'name' => $this->catalogItem->name,
                    'type' => $this->catalogItem->type,
                    'sku' => $this->catalogItem->sku,
                    'selling_price' => $this->catalogItem->selling_price,
                    'track_inventory' => $this->catalogItem->track_inventory,
                    'is_active' => $this->catalogItem->is_active,
                ];
            }),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}