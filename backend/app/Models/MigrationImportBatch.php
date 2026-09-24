<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'migration_session_id',
        'created_by',
        'entity_type',
        'status',
        'total_rows',
        'successful_rows',
        'failed_rows',
        'errors',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'successful_rows' => 'integer',
        'failed_rows' => 'integer',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}