<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EngageConversation extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'tenant_id',
        'contact_id',
        'channel',
        'external_conversation_id',
        'status',
        'assigned_to',
        'unread_count',
        'last_message_at',
        'last_inbound_at',
        'whatsapp_number_id',
    ];

    protected $casts = [
        'unread_count' => 'integer',
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(EngageContact::class, 'contact_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EngageMessage::class, 'conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(EngageMessage::class, 'conversation_id')
            ->latestOfMany();
    }

    public function whatsappNumber(): BelongsTo
    {
        return $this->belongsTo(
            EngageWhatsAppNumber::class,
            'whatsapp_number_id'
        );
    }
}