<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickBooksImportMapping extends Model
{
    protected $table = 'quickbooks_import_mappings';

    protected $fillable = [
        'tenant_id',
        'quickbooks_connection_id',
        'entity_type',
        'quickbooks_id',
        'local_type',
        'local_id',
        'payload_hash',
        'imported_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(QuickBooksConnection::class, 'quickbooks_connection_id');
    }
}
