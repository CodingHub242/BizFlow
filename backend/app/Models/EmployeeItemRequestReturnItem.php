<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeItemRequestReturnItem extends Model
{
    protected $fillable = [
        'tenant_id',
        'employee_item_request_return_id',
        'employee_item_request_issue_item_id',
        'inventory_batch_id',
        'quantity_returned',
        'return_condition',
        'notes',
    ];

    protected $casts = [
        'quantity_returned' => 'decimal:3',
    ];

    public function employeeItemRequestReturn(): BelongsTo
    {
        return $this->belongsTo(
            EmployeeItemRequestReturn::class,
            'employee_item_request_return_id'
        );
    }

    public function issueItem(): BelongsTo
    {
        return $this->belongsTo(
            EmployeeItemRequestIssueItem::class,
            'employee_item_request_issue_item_id'
        );
    }

    public function inventoryBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class);
    }
}