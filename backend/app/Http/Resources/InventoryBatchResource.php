<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'catalog_item_id' => $this->catalog_item_id,
            'batch_number' => $this->batch_number,

            'quantity_received' => $this->quantity_received,
            'quantity_remaining' => $this->quantity_remaining,

            'unit_cost' => $this->unit_cost,
            'selling_price' => $this->selling_price,

            'received_at' => $this->received_at,
            'source_type' => $this->source_type,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,

            'origin_batch_id' => $this->origin_batch_id,

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
                    'sku' => $this->catalogItem->sku,
                    'type' => $this->catalogItem->type,
                ];
            }),

            'origin_batch' => $this->whenLoaded('originBatch', function () {
                if (! $this->originBatch) {
                    return null;
                }

                return [
                    'id' => $this->originBatch->id,
                    'batch_number' => $this->originBatch->batch_number,
                ];
            }),

            'movements' => $this->whenLoaded('movements', function () {
                return $this->movements->map(fn ($movement) => [
                    'id' => $movement->id,
                    'type' => $movement->type,
                    'quantity' => $movement->quantity,
                    'reference_type' => $movement->reference_type,
                    'reference_id' => $movement->reference_id,
                    'notes' => $movement->notes,
                    'created_at' => $movement->created_at,
                ]);
            }),

            'allocations' => $this->whenLoaded('allocations', function () {
                return $this->allocations->map(fn ($allocation) => [
                    'id' => $allocation->id,
                    'source_type' => $allocation->source_type,
                    'source_id' => $allocation->source_id,
                    'source_item_id' => $allocation->source_item_id,
                    'quantity' => $allocation->quantity,
                    'unit_cost_snapshot' => $allocation->unit_cost_snapshot,
                    'unit_selling_price_snapshot' =>
                        $allocation->unit_selling_price_snapshot,
                ]);
            }),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}