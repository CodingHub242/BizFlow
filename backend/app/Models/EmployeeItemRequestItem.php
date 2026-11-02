<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeItemRequestItem extends Model
{
    protected $fillable = [
        'tenant_id',
        'employee_item_request_id',
        'catalog_item_id',
        'quantity_requested',
        'quantity_approved',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:3',
            'quantity_approved' => 'decimal:3',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            EmployeeItemRequest::class,
            'employee_item_request_id'
        );
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}