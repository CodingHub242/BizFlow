<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EngageMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'provider_message_id',
        'direction',
        'message_type',
        'body',
        'status',
        'media',
        'metadata',
        'sent_at',
        'delivered_at',
        'read_at',
        'received_at',
    ];

    protected $casts = [
        'media' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EngageConversation::class, 'conversation_id');
    }
}