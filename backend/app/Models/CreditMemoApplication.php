<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditMemoApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'credit_memo_id',
        'invoice_id',
        'amount_applied',
        'applied_at',
        'quickbooks_linked_txn_id',
        'source_data',
    ];

    protected function casts(): array
    {
        return [
            'amount_applied' => 'decimal:2',
            'applied_at' => 'datetime',
            'source_data' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creditMemo(): BelongsTo
    {
        return $this->belongsTo(CreditMemo::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
