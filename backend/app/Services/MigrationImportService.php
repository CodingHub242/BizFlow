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

    public function import(
        MigrationSession $session,
        MigrationImportBatch $batch,
        array $mapping,
        ?int $branchId = null
    ): MigrationImportBatch {
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