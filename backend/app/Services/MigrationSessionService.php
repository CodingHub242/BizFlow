<?php

namespace App\Services;

use App\MigrationSessionStatus;
use App\MigrationSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\MigrationAnalysisResult;
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

        $result = app(MigrationCsvAnalyzer::class)->analyze($session);

        $analysis = app(MigrationCsvAnalyzer::class)->analyze($session);

        return MigrationAnalysisResult::create([
            'tenant_id' => $session->tenant_id,
            'entity_type' => $analysis['entity_type'],
            'migration_session_id' => $session->id,
            'row_count' => $result['row_count'],
            'headers' => $result['headers'],
            'sample_rows' => $analysis['sample_rows'],
        ]);
    }
}