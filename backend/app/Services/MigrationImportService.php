<?php

namespace App\Services;

use App\MigrationSessionStatus;
use App\Models\MigrationImportBatch;
use App\Models\MigrationSession;
use RuntimeException;

class MigrationImportService
{
    public function __construct(
        private MigrationCustomerImporter $customerImporter,
        private MigrationSupplierImporter $supplierImporter,
        private MigrationCatalogItemImporter $catalogItemImporter,
        private MigrationInvoiceImporter $invoiceImporter,
        private MigrationExpenseImporter $expenseImporter,
        private MigrationInvoicePaymentImporter $invoicePaymentImporter,
    ) {
    }

    public function import(MigrationSession $session,MigrationImportBatch $batch,array $mapping,?int $branchId = null): MigrationImportBatch 
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

        if ($batch->status !== 'pending') {
            throw new RuntimeException(
                'Only pending migration import batches can be imported.'
            );
        }

        if ($session->status !== MigrationSessionStatus::UPLOADED) {
            throw new RuntimeException(
                'Only uploaded migration sessions can be imported.'
            );
        }

        $this->ensureDependenciesImported($session, $batch);

        return match ($batch->entity_type) {
            'customers' => $this->customerImporter->import(
                $session,
                $batch,
                $mapping
            ),

            'suppliers' => $this->supplierImporter->import(
                $session,
                $batch,
                $mapping
            ),

            'catalog_items' => $this->catalogItemImporter->import(
                $session,
                $batch,
                $mapping
            ),

            'invoices' => $this->invoiceImporter->import(
                $session,
                $batch,
                $mapping,
                $this->requireBranchId($branchId)
            ),

            'expenses' => $this->expenseImporter->import(
                $session,
                $batch,
                $mapping,
                $this->requireBranchId($branchId)
            ),

            'payments' => $this->invoicePaymentImporter->import(
                $session,
                $batch,
                $mapping
            ),

            default => throw new RuntimeException(
                "Unsupported migration entity type: {$batch->entity_type}"
            ),
        };
    }

    private function ensureDependenciesImported(MigrationSession $session,MigrationImportBatch $batch): void 
    {
        $dependencies = match ($batch->entity_type) {
            'customers' => [],
            'suppliers' => [],
            'catalog_items' => [],
            'invoices' => ['customers'],
            'expenses' => [],
            'payments' => ['invoices'],
            default => throw new RuntimeException(
                "Unsupported migration entity type: {$batch->entity_type}"
            ),
        };

        foreach ($dependencies as $dependency) {
            $dependencyBatch = MigrationImportBatch::query()
                ->where('tenant_id', $session->tenant_id)
                ->where('migration_session_id', $session->id)
                ->where('entity_type', $dependency)
                ->first();

            if (!$dependencyBatch) {
                throw new RuntimeException(
                    "Migration dependency '{$dependency}' must be imported before '{$batch->entity_type}'."
                );
            }

            if ($dependencyBatch->status !== 'completed') {
                throw new RuntimeException(
                    "Migration dependency '{$dependency}' must be completed before '{$batch->entity_type}'."
                );
            }
        }
    }

    public function retry(MigrationSession $session,MigrationImportBatch $batch,array $mapping,?int $branchId = null): MigrationImportBatch 
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

        if ($batch->status !== 'failed') {
            throw new RuntimeException(
                'Only failed migration import batches can be retried.'
            );
        }

        $batch->update([
            'status' => 'pending',
            'successful_rows' => 0,
            'failed_rows' => 0,
            'errors' => [],
        ]);

        return $this->import(
            $session,
            $batch->fresh(),
            $mapping,
            $branchId
        );
    }

    private function requireBranchId(?int $branchId): int
    {
        if ($branchId === null) {
            throw new RuntimeException(
                'A branch ID is required for this migration entity type.'
            );
        }

        return $branchId;
    }
}