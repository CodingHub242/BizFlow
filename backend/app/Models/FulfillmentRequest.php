<?php

namespace App\Models;

use App\FulfillmentSourceType;
use App\FulfillmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FulfillmentRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'order_item_id',
        'branch_id',
        'catalog_item_id',
        'requested_quantity',
        'available_quantity',
        'fulfilled_quantity',
        'shortfall_quantity',
        'status',
        'source_type',
        'source_branch_id',
        'source_supplier_id',
        'assigned_to',
        'expected_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => FulfillmentStatus::class,
            'source_type' => FulfillmentSourceType::class,
            'requested_quantity' => 'decimal:3',
            'available_quantity' => 'decimal:3',
            'fulfilled_quantity' => 'decimal:3',
            'shortfall_quantity' => 'decimal:3',
            'expected_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function sourceBranch()
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function catalogItem()
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}