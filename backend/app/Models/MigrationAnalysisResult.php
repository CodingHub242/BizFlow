<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationAnalysisResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'migration_session_id',
        'entity_type',
        'row_count',
        'headers',
        'sample_rows',
    ];

    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'headers' => 'array',
            'sample_rows' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function migrationSession(): BelongsTo
    {
        return $this->belongsTo(MigrationSession::class);
    }
}