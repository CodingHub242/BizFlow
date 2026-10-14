<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickBooksConnection extends Model
{
    protected $table = 'quickbooks_connections';
    
    protected $fillable = [
        'tenant_id',
        'connected_by',
        'realm_id',
        'company_name',
        'connection_status',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'refresh_token_expires_at',
        'scopes',
        'connected_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            // Never store OAuth bearer tokens as plaintext in the database.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'immutable_datetime',
            'refresh_token_expires_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'scopes' => 'array',
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
}
