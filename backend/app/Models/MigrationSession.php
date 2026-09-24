<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\MigrationMapping;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'created_by',
        'source',
        'status',
        'original_filename',
        'file_path',
        'file_size',
        'mime_type',
    ];

    protected function casts(): array
    {
        return [
            'status' => \App\MigrationSessionStatus::class,
            'source' => \App\MigrationSource::class,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function analysisResult(): HasOne
    {
        return $this->hasOne(MigrationAnalysisResult::class);
    }

    public function mapping(): HasOne
    {
        return $this->hasOne(MigrationMapping::class);
    }

    public function validationResults()
    {
        return $this->hasMany(MigrationValidationResult::class);
    }

    public function importBatches()
    {
        return $this->hasMany(MigrationImportBatch::class);
    }
}