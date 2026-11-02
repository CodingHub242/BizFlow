<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryBatchMovement extends Model
{
    protected $table = "inventory_batch_movements";

    protected $fillable = [ 
        "tenant_id", 
        "branch_id", 
        "inventory_batch_id", 
        "type", 
        "quantity", 
        "reference_type", 
        "reference_id", 
        "notes", 
        "created_by" 
    ];

    protected $casts = [ 
        "quantity" => "decimal:3" 
    ];

    public function batch(): BelongsTo { 
        return $this->belongsTo(InventoryBatch::class, "inventory_batch_id"); 
    }
}
