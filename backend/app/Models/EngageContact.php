<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EngageContact extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'name',
        'phone',
        'normalized_phone',
        'email',
        'company_name',
        'source',
        'lifecycle_status',
        'status',
        'notes',
        'first_contacted_at',
        'last_contacted_at',
    ];

    protected $casts = [
        'first_contacted_at' => 'datetime',
        'last_contacted_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(EngageConversation::class, 'contact_id');
    }
}