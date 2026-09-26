<?php

namespace App\Services;

use App\Models\Invoice;
use RuntimeException;

class MigrationInvoiceResolver
{
    public function resolve(int $tenantId, string $invoiceNumber): Invoice
    {
        $invoiceNumber = trim($invoiceNumber);

        if ($invoiceNumber === '') {
            throw new RuntimeException(
                'Invoice number is required to resolve a payment invoice.'
            );
        }

        $invoice = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('invoice_number', $invoiceNumber)
            ->first();

        if (!$invoice) {
            throw new RuntimeException(
                "Invoice '{$invoiceNumber}' could not be found for this tenant."
            );
        }

        return $invoice;
    }
}