<?php

namespace App\Services;

use App\MigrationSessionStatus;
use App\MigrationSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\MigrationAnalysisResult;
use App\Models\MigrationImportBatch;
use App\Models\MigrationValidationResult;
use App\Models\MigrationSession;
use App\Services\MigrationCsvAnalyzer;

class MigrationSessionService
{
    public function create(int $tenantId,int $createdBy,MigrationSource $source = MigrationSource::QUICKBOOKS,): MigrationSession 
    {
        return MigrationSession::create([
            'tenant_id' => $tenantId,
            'created_by' => $createdBy,
            'source' => $source,
            'status' => MigrationSessionStatus::PENDING,
        ]);
    }

    public function attachFile(MigrationSession $session,UploadedFile $file,): MigrationSession 
    {
        if ($session->status !== MigrationSessionStatus::PENDING) {
            throw new \RuntimeException(
                'A migration file can only be attached to a pending migration session.'
            );
        }

        if ($file->getMimeType() !== 'text/csv') {
            throw new \RuntimeException(
                'Only CSV files are supported for QuickBooks migration.'
            );
        }

        //allow upto 10MB
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new \RuntimeException(
                'The QuickBooks migration file must not exceed 10 MB.'
            );
        }

        $path = $file->store('migration-imports');

        $session->update([
            'status' => MigrationSessionStatus::UPLOADED,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        return $session->refresh();
    }

    public function beginAnalysis(MigrationSession $session): MigrationSession
    {
        if ($session->status !== MigrationSessionStatus::UPLOADED) {
            throw new \RuntimeException(
                'Only uploaded migration sessions can begin analysis.'
            );
        }

        $session->update([
            'status' => MigrationSessionStatus::ANALYZING,
        ]);

        return $session->refresh();
    }

    public function analyze(MigrationSession $session): MigrationAnalysisResult
    {
        if ($session->status !== MigrationSessionStatus::UPLOADED) {
            throw new \RuntimeException(
                'Only uploaded migration sessions can be analyzed.'
            );
        }

        $session->update([
            'status' => MigrationSessionStatus::ANALYZING,
        ]);

        $analysis = app(MigrationCsvAnalyzer::class)->analyze($session);

        return MigrationAnalysisResult::create([
            'tenant_id' => $session->tenant_id,
            'entity_type' => $analysis['entity_type'],
            'migration_session_id' => $session->id,
            'row_count' => $analysis['row_count'],
            'headers' => $analysis['headers'],
            'sample_rows' => $analysis['sample_rows'],
        ]);
    }

    public function validateMappedRows(MigrationSession $session,string $entityType,array $mapping): array 
    {
        if ($session->status !== \App\MigrationSessionStatus::UPLOADED) {
            throw new \RuntimeException(
                'Migration session must be uploaded before validation.'
            );
        }

        if (!$session->file_path) {
            throw new \RuntimeException(
                'The migration session does not have an uploaded file.'
            );
        }

        app(\App\Services\MigrationMappingService::class)
            ->validate($entityType, $mapping);

        $stream = \Illuminate\Support\Facades\Storage::disk('local')
            ->readStream($session->file_path);

        if ($stream === false) {
            throw new \RuntimeException(
                'Unable to open the migration file.'
            );
        }

        $headers = fgetcsv($stream);

        if ($headers === false) {
            fclose($stream);

            $result = [
                'total_rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'errors' => [],
            ];

            \App\Models\MigrationValidationResult::updateOrCreate(
                [
                    'tenant_id' => $session->tenant_id,
                    'migration_session_id' => $session->id,
                    'entity_type' => $entityType,
                ],
                $result
            );

            return $result;
        }

        $mappingService = app(
            \App\Services\MigrationMappingService::class
        );

        $validationService = app(
            \App\Services\MigrationValidationService::class
        );

        $rows = [];

        while (($row = fgetcsv($stream)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            $rows[] = $mappingService->transformRow(
                $entityType,
                array_combine($headers, $row),
                $mapping
            );
        }

        fclose($stream);

        $result = $validationService->validateRows(
            $entityType,
            $rows
        );

        \App\Models\MigrationValidationResult::updateOrCreate(
            [
                'tenant_id' => $session->tenant_id,
                'migration_session_id' => $session->id,
                'entity_type' => $entityType,
            ],
            [
                'total_rows' => $result['total_rows'],
                'valid_rows' => $result['valid_rows'],
                'invalid_rows' => $result['invalid_rows'],
                'errors' => $result['errors'],
            ]
        );

        return $result;
    }

    public function review(MigrationSession $session, string $entityType): array
    {
        if ($session->status !== MigrationSessionStatus::UPLOADED) {
            throw new \RuntimeException(
                'Migration session must be uploaded before review.'
            );
        }

        $analysis = MigrationAnalysisResult::query()
            ->where('tenant_id', $session->tenant_id)
            ->where('migration_session_id', $session->id)
            ->where('entity_type', $entityType)
            ->first();

        if (!$analysis) {
            throw new \RuntimeException(
                'Migration analysis must be completed before review.'
            );
        }

        $mapping = \App\Models\MigrationMapping::query()
            ->where('tenant_id', $session->tenant_id)
            ->where('migration_session_id', $session->id)
            ->where('entity_type', $entityType)
            ->first();

        if (!$mapping) {
            throw new \RuntimeException(
                'Migration mapping must be completed before review.'
            );
        }

        $validation = MigrationValidationResult::query()
            ->where('tenant_id', $session->tenant_id)
            ->where('migration_session_id', $session->id)
            ->where('entity_type', $entityType)
            ->first();

        if (!$validation) {
            throw new \RuntimeException(
                'Migration data must be validated before review.'
            );
        }

        $batch = \App\Models\MigrationImportBatch::query()
            ->where('tenant_id', $session->tenant_id)
            ->where('migration_session_id', $session->id)
            ->where('entity_type', $entityType)
            ->first();

        return [
            'session' => [
                'id' => $session->id,
                'source' => $session->source->value,
                'status' => $session->status->value,
                'original_filename' => $session->original_filename,
            ],

            'analysis' => [
                'id' => $analysis->id,
                'entity_type' => $analysis->entity_type,
                'row_count' => $analysis->row_count,
                'headers' => $analysis->headers,
                'sample_rows' => $analysis->sample_rows,
            ],

            'mapping' => [
                'id' => $mapping->id,
                'entity_type' => $mapping->entity_type,
                'field_mapping' => $mapping->field_mapping,
            ],

            'validation' => [
                'total_rows' => $validation->total_rows,
                'valid_rows' => $validation->valid_rows,
                'invalid_rows' => $validation->invalid_rows,
                'errors' => $validation->errors,
            ],

            'import' => [
                'ready' => $validation->invalid_rows === 0,
                'batch_exists' => $batch !== null,
                'batch' => $batch ? [
                    'id' => $batch->id,
                    'status' => $batch->status,
                    'total_rows' => $batch->total_rows,
                    'successful_rows' => $batch->successful_rows,
                    'failed_rows' => $batch->failed_rows,
                    'errors' => $batch->errors,
                ] : null,
            ],
        ];
    }

    public function createImportBatch(MigrationSession $session,int $createdBy,string $entityType): \App\Models\MigrationImportBatch 
    {
        if ($session->status !== \App\MigrationSessionStatus::UPLOADED) {
            throw new \RuntimeException(
                'Migration session must be uploaded before import.'
            );
        }

        if (!$session->file_path) {
            throw new \RuntimeException(
                'The migration session does not have an uploaded file.'
            );
        }

        if ($session->tenant_id === null) {
            throw new \RuntimeException(
                'Migration session must belong to a tenant.'
            );
        }

        $validationResult = \App\Models\MigrationValidationResult::query()
            ->where('tenant_id', $session->tenant_id)
            ->where('migration_session_id', $session->id)
            ->where('entity_type', $entityType)
            ->first();

        if (!$validationResult) {
            throw new \RuntimeException(
                'Migration data must be validated before import.'
            );
        }

        if ($validationResult->invalid_rows > 0) {
            throw new \RuntimeException(
                'Migration cannot be imported while validation errors exist.'
            );
        }

        $existingBatch = \App\Models\MigrationImportBatch::query()
            ->where('tenant_id', $session->tenant_id)
            ->where('migration_session_id', $session->id)
            ->where('entity_type', $entityType)
            ->exists();

        if ($existingBatch) {
            throw new \RuntimeException(
                'An import batch already exists for this migration entity.'
            );
        }

        return \App\Models\MigrationImportBatch::create([
            'tenant_id' => $session->tenant_id,
            'migration_session_id' => $session->id,
            'created_by' => $createdBy,
            'entity_type' => $entityType,
            'status' => 'pending',
            'total_rows' => $validationResult->total_rows,
            'successful_rows' => 0,
            'failed_rows' => 0,
            'errors' => [],
        ]);
    }

    public function report(MigrationSession $session): array
    {
        $batches = MigrationImportBatch::query()
            ->where('tenant_id', $session->tenant_id)
            ->where('migration_session_id', $session->id)
            ->orderBy('id')
            ->get();

        $totalRows = $batches->sum('total_rows');
        $successfulRows = $batches->sum('successful_rows');
        $failedRows = $batches->sum('failed_rows');

        $completedBatches = $batches
            ->where('status', 'completed')
            ->count();

        $failedBatches = $batches
            ->where('status', 'failed')
            ->count();

        $pendingBatches = $batches
            ->where('status', 'pending')
            ->count();

        $totalBatches = $batches->count();

        return [
            'session' => [
                'id' => $session->id,
                'tenant_id' => $session->tenant_id,
                'source' => $session->source,
                'status' => $session->status,
            ],

            'summary' => [
                'total_batches' => $totalBatches,
                'completed_batches' => $completedBatches,
                'failed_batches' => $failedBatches,
                'pending_batches' => $pendingBatches,
                'total_rows' => $totalRows,
                'successful_rows' => $successfulRows,
                'failed_rows' => $failedRows,
                'complete' => $totalBatches > 0
                    && $completedBatches === $totalBatches,
            ],

            'batches' => $batches->map(function (
                MigrationImportBatch $batch
            ) {
                return [
                    'id' => $batch->id,
                    'entity_type' => $batch->entity_type,
                    'status' => $batch->status,
                    'total_rows' => $batch->total_rows,
                    'successful_rows' => $batch->successful_rows,
                    'failed_rows' => $batch->failed_rows,
                    'errors' => $batch->errors ?? [],
                ];
            })->values()->all(),
        ];
    }
}