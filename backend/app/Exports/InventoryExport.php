<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class InventoryExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(
        private readonly Builder $query
    ) {
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'branch_code',
            'branch_name',
            'sku',
            'product_name',
            'quantity',
            'reorder_level',
            'selling_price',
            'stock_status',
        ];
    }

    public function map(mixed $inventory): array
    {
        $quantity = (float) $inventory->quantity;
        $reorderLevel = (float) $inventory->reorder_level;

        $status = $quantity <= 0
            ? 'out-of-stock'
            : ($quantity <= $reorderLevel ? 'low-stock' : 'in-stock');

        return [
            $inventory->branch?->code,
            $inventory->branch?->name,
            $inventory->catalogItem?->sku,
            $inventory->catalogItem?->name,
            $inventory->quantity,
            $inventory->reorder_level,
            $inventory->catalogItem?->selling_price,
            $status,
        ];
    }
}