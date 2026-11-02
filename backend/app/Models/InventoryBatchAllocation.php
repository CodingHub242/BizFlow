<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryBatchAllocation extends Model
{
    protected $table = "inventory_batch_allocations";

    protected $fillable = [ 
        "tenant_id", 
        "branch_id", 
        "catalog_item_id", 
        "inventory_batch_id", 
        "source_type", 
        "source_id", 
        "source_item_id", 
        "quantity", 
        "unit_cost_snapshot", 
        "unit_selling_price_snapshot" 
    ];

    protected $casts = [ 
        "quantity" => "decimal:3", 
        "unit_cost_snapshot" => "decimal:2", 
        "unit_selling_price_snapshot" => "decimal:2" 
    ];

    public function batch(): BelongsTo { 
        return $this->belongsTo(InventoryBatch::class, "inventory_batch_id"); 
    }
}
