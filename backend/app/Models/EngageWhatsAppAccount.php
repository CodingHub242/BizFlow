<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EngageWhatsAppAccount extends Model
{
    protected $table = 'engage_whatsapp_accounts';
    
    protected $fillable = [
        'tenant_id',
        'business_id',
        'waba_id',
        'name',
        'access_token',
        'token_expires_at',
        'status',
        'connected_by',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function numbers(): HasMany
    {
        return $this->hasMany(
            EngageWhatsAppNumber::class,
            'whatsapp_account_id'
        );
    }
}