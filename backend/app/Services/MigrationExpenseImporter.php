<?php

namespace App\Services;

use App\Models\MigrationImportBatch;
use App\Models\MigrationSession;
use App\ExpensePaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MigrationExpenseImporter
{
    private MigrationExpenseCreator $expenseCreator;
    private MigrationCategoryResolver $categoryResolver;

    public function __construct(?MigrationExpenseCreator $expenseCreator = null,?MigrationCategoryResolver $categoryResolver = null) 
    {
        $this->expenseCreator = $expenseCreator
            ?? app(MigrationExpenseCreator::class);

        $this->categoryResolver = $categoryResolver
            ?? app(MigrationCategoryResolver::class);
    }

    public function import(MigrationSession $session,MigrationImportBatch $batch,array $mapping,int $branchId): MigrationImportBatch 
    {
        if ($session->tenant_id !== $batch->tenant_id) {
            throw new RuntimeException(
                'Migration session and import batch must belong to the same tenant.'
            );
        }

        if ($batch->migration_session_id !== $session->id) {
            throw new RuntimeException(
                'Import batch does not belong to the migration session.'
            );
        }

        if ($batch->entity_type !== 'expenses') {
            throw new RuntimeException(
                'Import batch must be for expenses.'
            );
        }

        if ($batch->status !== 'pending') {
            throw new RuntimeException(
                'Import batch is not pending.'
            );
        }

        $validationResult = $session->validationResults()
            ->where('tenant_id', $session->tenant_id)
            ->where('entity_type', 'expenses')
            ->first();

        if (!$validationResult) {
            throw new RuntimeException(
                'Expense migration must be validated before import.'
            );
        }

        if ($validationResult->invalid_rows > 0) {
            throw new RuntimeException(
                'Expense migration contains validation errors.'
            );
        }

        if (!$session->file_path) {
            throw new RuntimeException(
                'The migration session does not have an uploaded file.'
            );
        }

        $stream = Storage::disk('local')
            ->readStream($session->file_path);

        if ($stream === false) {
            throw new RuntimeException(
                'Unable to open the migration file.'
            );
        }

        $headers = fgetcsv($stream);

        if ($headers === false) {
            fclose($stream);

            throw new RuntimeException(
                'The migration file is empty.'
            );
        }

        $mappingService = app(MigrationMappingService::class);

        $successfulRows = 0;
        $failedRows = 0;
        $errors = [];
        $totalRows = 0;

        DB::beginTransaction();

        try {
            while (($row = fgetcsv($stream)) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }

                $totalRows++;

                $sourceRow = array_combine($headers, $row);

                try {
                    $data = $mappingService->transformRow(
                        'expenses',
                        $sourceRow,
                        $mapping
                    );

                    $data['payment_method'] = match (
                        strtolower(trim((string) ($data['payment_method'] ?? '')))
                    ) {
                        'cash' => ExpensePaymentMethod::CASH->value,
                        'mobile money', 'mobile_money' => ExpensePaymentMethod::MOBILE_MONEY->value,
                        'bank transfer', 'bank_transfer' => ExpensePaymentMethod::BANK_TRANSFER->value,
                        'card' => ExpensePaymentMethod::CARD->value,
                        default => ExpensePaymentMethod::OTHER->value,
                    };

                    $categoryName = (string) (
                        $sourceRow['Category'] ?? ''
                    );

                    $category = $this->categoryResolver->resolve(
                        $session->tenant_id,
                        $categoryName
                    );

                    $data['category_id'] = $category->id;

                    $this->expenseCreator->create(
                        $session->tenant_id,
                        [
                            ...$data,
                            'branch_id' => $branchId,
                            'created_by' => $session->created_by,
                        ]
                    );

                    $successfulRows++;
                } catch (\Throwable $exception) {
                    $failedRows++;

                    $errors[] = [
                        'row' => $totalRows,
                        'messages' => [
                            $exception->getMessage(),
                        ],
                    ];
                }
            }

            fclose($stream);

            if ($failedRows > 0) {
                DB::rollBack();

                $batch->update([
                    'total_rows' => $totalRows,
                    'successful_rows' => 0,
                    'failed_rows' => $failedRows,
                    'errors' => $errors,
                    'status' => 'failed',
                ]);

                return $batch->fresh();
            }

            $batch->update([
                'total_rows' => $totalRows,
                'successful_rows' => $successfulRows,
                'failed_rows' => 0,
                'errors' => [],
                'status' => 'completed',
            ]);

            DB::commit();

            return $batch->fresh();
        } catch (\Throwable $exception) {
            fclose($stream);

            DB::rollBack();

            $batch->update([
                'total_rows' => $totalRows,
                'successful_rows' => 0,
                'failed_rows' => $failedRows,
                'errors' => [
                    [
                        'row' => $totalRows,
                        'messages' => [
                            $exception->getMessage(),
                        ],
                    ],
                ],
                'status' => 'failed',
            ]);

            throw $exception;
        }
    }
}