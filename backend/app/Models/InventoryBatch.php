<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Branch;
use App\Models\CatalogItem;

class InventoryBatch extends Model
{
    protected $table = "inventory_batches";

    protected $fillable = [ 
        "tenant_id", 
        "branch_id", 
        "catalog_item_id", 
        "batch_number", 
        "quantity_received", 
        "quantity_remaining", 
        "unit_cost", 
        "selling_price", 
        "received_at", 
        "source_type", 
        "reference_type", 
        "reference_id", 
        "origin_batch_id", 
        "created_by" 
        ];

    protected $casts = [ 
        "quantity_received" => "decimal:3", 
        "quantity_remaining" => "decimal:3", 
        "unit_cost" => "decimal:2", 
        "selling_price" => "decimal:2", 
        "received_at" => "datetime" 
        ];

    public function movements(): HasMany { 
        return $this->hasMany(InventoryBatchMovement::class); 
    }

    public function allocations(): HasMany { 
        return $this->hasMany(InventoryBatchAllocation::class); 
    }

    public function originBatch(): BelongsTo { 
        return $this->belongsTo(self::class, "origin_batch_id"); 
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
