<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditMemoItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'credit_memo_id',
        'catalog_item_id',
        'line_number',
        'source_line_id',
        'description',
        'quantity',
        'unit_price',
        'discount',
        'tax',
        'line_total',
        'source_data',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'line_total' => 'decimal:2',
            'source_data' => 'array',
        ];
    }

    public function creditMemo(): BelongsTo
    {
        return $this->belongsTo(CreditMemo::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}