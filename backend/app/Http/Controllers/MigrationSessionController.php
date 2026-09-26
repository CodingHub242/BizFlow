<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMigrationSessionRequest;
use App\Http\Requests\StoreMigrationMappingRequest;
use App\Http\Requests\ValidateMigrationRequest;
use App\Http\Requests\StoreMigrationImportBatchRequest;
use App\Http\Requests\RunMigrationImportRequest;
use App\Models\MigrationImportBatch;
use App\Services\MigrationImportService;
use App\Models\MigrationSession;
use App\Services\MigrationSessionService;
use App\Services\MigrationMappingService;
use App\Http\Requests\UploadMigrationFileRequest;
use App\Http\Requests\RetryMigrationImportRequest;
use Illuminate\Http\JsonResponse;
use Throwable;

class MigrationSessionController extends Controller
{
    public function upload(UploadMigrationFileRequest $request,MigrationSession $session,MigrationSessionService $migrationSessionService): JsonResponse 
    {
        if ($session->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        $session = $migrationSessionService->attachFile(
            $session,
            $request->file('file')
        );

        return response()->json([
            'success' => true,
            'message' => 'Migration file uploaded successfully.',
            'data' => [
                'id' => $session->id,
                'source' => $session->source->value,
                'status' => $session->status->value,
                'original_filename' => $session->original_filename,
                'file_size' => $session->file_size,
                'mime_type' => $session->mime_type,
            ],
        ]);
    }

    public function analyze(MigrationSession $session,MigrationSessionService $migrationSessionService): JsonResponse 
    {
        if ($session->tenant_id !== request()->user()->tenant_id) {
            abort(404);
        }

        $analysis = $migrationSessionService->analyze($session);

        return response()->json([
            'success' => true,
            'message' => 'Migration file analyzed successfully.',
            'data' => [
                'id' => $analysis->id,
                'migration_session_id' => $analysis->migration_session_id,
                'entity_type' => $analysis->entity_type,
                'row_count' => $analysis->row_count,
                'headers' => $analysis->headers,
                'sample_rows' => $analysis->sample_rows,
            ],
        ]);
    }

    public function store(StoreMigrationSessionRequest $request,MigrationSessionService $migrationSessionService): JsonResponse 
    {
        $session = $migrationSessionService->create(
            $request->user()->tenant_id,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Migration session created successfully.',
            'data' => [
                'id' => $session->id,
                'source' => $session->source->value,
                'status' => $session->status->value,
                'created_at' => $session->created_at,
            ],
        ], 201);
    }

    public function storeMapping(StoreMigrationMappingRequest $request,MigrationSession $session,MigrationMappingService $migrationMappingService): JsonResponse 
    {
        if ($session->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        $entityType = $request->validated('entity_type');
        $fieldMapping = $request->validated('field_mapping');

        $migrationMappingService->validate(
            $entityType,
            $fieldMapping
        );

        $mapping = $migrationMappingService->create(
            $session,
            $entityType,
            $fieldMapping
        );

        return response()->json([
            'success' => true,
            'message' => 'Migration mapping saved successfully.',
            'data' => [
                'id' => $mapping->id,
                'migration_session_id' => $mapping->migration_session_id,
                'entity_type' => $mapping->entity_type,
                'field_mapping' => $mapping->field_mapping,
            ],
        ]);
    }

    public function validateMigration(ValidateMigrationRequest $request,MigrationSession $session,MigrationSessionService $migrationSessionService): JsonResponse 
    {
        if ($session->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        $result = $migrationSessionService->validateMappedRows(
            $session,
            $request->validated('entity_type'),
            $request->validated('field_mapping')
        );

        return response()->json([
            'success' => true,
            'message' => 'Migration data validated successfully.',
            'data' => [
                'total_rows' => $result['total_rows'],
                'valid_rows' => $result['valid_rows'],
                'invalid_rows' => $result['invalid_rows'],
                'errors' => $result['errors'],
            ],
        ]);
    }

    public function review(MigrationSession $session,MigrationSessionService $migrationSessionService): JsonResponse 
    {
        if ($session->tenant_id !== request()->user()->tenant_id) {
            abort(404);
        }

        $entityType = request()->query('entity_type');

        if (!is_string($entityType) || $entityType === '') {
            return response()->json([
                'success' => false,
                'message' => 'The entity_type query parameter is required.',
            ], 422);
        }

        try {
            $review = $migrationSessionService->review(
                $session,
                $entityType
            );
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Migration review could not be completed.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Migration review generated successfully.',
            'data' => $review,
        ]);
    }

    public function createBatch(StoreMigrationImportBatchRequest $request,MigrationSession $session,MigrationSessionService $migrationSessionService): JsonResponse 
    {
        if ($session->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        $batch = $migrationSessionService->createImportBatch(
            $session,
            $request->user()->id,
            $request->validated('entity_type')
        );

        return response()->json([
            'success' => true,
            'message' => 'Migration import batch created successfully.',
            'data' => [
                'id' => $batch->id,
                'migration_session_id' => $batch->migration_session_id,
                'entity_type' => $batch->entity_type,
                'status' => $batch->status,
                'total_rows' => $batch->total_rows,
                'successful_rows' => $batch->successful_rows,
                'failed_rows' => $batch->failed_rows,
                'errors' => $batch->errors,
            ],
        ], 201);
    }

    public function import(RunMigrationImportRequest $request,MigrationSession $session,MigrationImportBatch $batch,MigrationImportService $migrationImportService): JsonResponse 
    {
        if ($session->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        if ($batch->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        if ($batch->migration_session_id !== $session->id) {
            abort(404);
        }

        try {
            $batch = $migrationImportService->import(
                $session,
                $batch,
                $request->validated('mapping'),
                $request->validated('branch_id')
            );
        } catch (\Throwable $exception) {
            report($exception);

            $batch = $batch->fresh();

            return response()->json([
                'success' => false,
                'message' => 'Migration import failed.',
                'data' => [
                    'id' => $batch->id,
                    'migration_session_id' => $batch->migration_session_id,
                    'entity_type' => $batch->entity_type,
                    'status' => $batch->status,
                    'total_rows' => $batch->total_rows,
                    'successful_rows' => $batch->successful_rows,
                    'failed_rows' => $batch->failed_rows,
                    'errors' => $batch->errors,
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Migration import completed successfully.',
            'data' => [
                'id' => $batch->id,
                'migration_session_id' => $batch->migration_session_id,
                'entity_type' => $batch->entity_type,
                'status' => $batch->status,
                'total_rows' => $batch->total_rows,
                'successful_rows' => $batch->successful_rows,
                'failed_rows' => $batch->failed_rows,
                'errors' => $batch->errors,
            ],
        ]);
    }

    public function retry(RetryMigrationImportRequest $request,MigrationSession $session,MigrationImportBatch $batch,MigrationImportService $service): JsonResponse 
    {
        if ($session->tenant_id !== $request->user()->tenant_id) {
            return response()->json([
                'message' => 'Migration session not found.',
            ], 404);
        }

        if ($batch->tenant_id !== $request->user()->tenant_id) {
            return response()->json([
                'message' => 'Migration batch not found.',
            ], 404);
        }

        try {
            $result = $service->retry(
                $session,
                $batch,
                $request->validated('mapping'),
                $request->validated('branch_id')
            );

            return response()->json([
                'message' => 'Migration import retried successfully.',
                'batch' => $result,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function report(MigrationSession $session,MigrationSessionService $service): JsonResponse 
    {
        if ($session->tenant_id !== request()->user()->tenant_id) {
            return response()->json([
                'message' => 'Migration session not found.',
            ], 404);
        }

        try {
            return response()->json(
                $service->report($session)
            );
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Unable to generate migration report.',
            ], 422);
        }
    }
}