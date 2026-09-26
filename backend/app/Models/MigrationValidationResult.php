<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationValidationResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'migration_session_id',
        'entity_type',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'errors',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'invalid_rows' => 'integer',
        'errors' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function migrationSession(): BelongsTo
    {
        return $this->belongsTo(MigrationSession::class);
    }
}