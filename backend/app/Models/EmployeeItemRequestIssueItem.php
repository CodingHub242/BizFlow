<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeItemRequestIssueItem extends Model
{
    protected $fillable = [
        'tenant_id',
        'employee_item_request_issue_id',
        'employee_item_request_item_id',
        'catalog_item_id',
        'quantity_issued',
        'tracks_inventory',
        'notes',
    ];

    protected $casts = [
        'quantity_issued' => 'decimal:3',
        'tracks_inventory' => 'boolean',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(EmployeeItemRequestIssue::class, 'employee_item_request_issue_id');
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(EmployeeItemRequestItem::class, 'employee_item_request_item_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(EmployeeItemRequestReturnItem::class);
    }
}