<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estimate extends Model
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'customer_id',
        'created_by',
        'estimate_number',
        'estimate_date',
        'expiry_date',
        'total',
        'status',
        'source_status',
        'currency',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'estimate_date' => 'date',
            'expiry_date' => 'date',
            'total' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class);
    }
}
