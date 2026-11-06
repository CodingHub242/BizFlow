<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EngageWhatsAppNumber extends Model
{
    protected $table = 'engage_whatsapp_numbers';

    protected $fillable = [
        'tenant_id',
        'whatsapp_account_id',
        'phone_number_id',
        'display_phone_number',
        'verified_name',
        'quality_rating',
        'messaging_limit_tier',
        'is_default',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            EngageWhatsAppAccount::class,
            'whatsapp_account_id'
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(
            EngageConversation::class,
            'whatsapp_number_id'
        );
    }
}