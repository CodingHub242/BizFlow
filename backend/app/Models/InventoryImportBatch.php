<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryImportBatch extends Model
{
    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'file_name',
        'file_path',
        'file_type',
        'status',
        'row_count',
        'valid_rows',
        'invalid_rows',
        'preview_rows',
        'expires_at',
        'imported_at',
        'imported_rows',
        'skipped_rows',
    ];

    protected function casts(): array
    {
        return [
            'preview_rows' => 'array',
            'expires_at' => 'datetime',
            'imported_at' => 'datetime',
            'row_count' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'imported_rows' => 'integer',
            'skipped_rows' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}