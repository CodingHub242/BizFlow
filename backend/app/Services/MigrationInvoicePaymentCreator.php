<?php

namespace App\Services;

use App\InvoicePaymentMethod;
use App\Models\InvoicePayment;

class MigrationInvoicePaymentCreator
{
    public function create(int $tenantId, array $data): InvoicePayment
    {
        return InvoicePayment::create([
            'tenant_id' => $tenantId,
            'invoice_id' => $data['invoice_id'],
            'recorded_by' => $data['recorded_by'],
            'amount' => $data['amount'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'paid_at' => $data['paid_at'],
        ]);
    }
}