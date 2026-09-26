<?php

namespace App\Services;

use App\Models\Invoice;
use RuntimeException;

class MigrationInvoiceCreator
{
    public function create(int $tenantId, array $data): Invoice
    {
        $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));

        if ($invoiceNumber === '') {
            throw new RuntimeException(
                'Invoice number is required.'
            );
        }

        $existing = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('invoice_number', $invoiceNumber)
            ->first();

        if ($existing) {
            return $existing;
        }

        return Invoice::create([
            'tenant_id' => $tenantId,
            'branch_id' => $data['branch_id'],
            'customer_id' => $data['customer_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'created_by' => $data['created_by'],
            'invoice_number' => $invoiceNumber,
            'status' => $data['status'] ?? 'issued',
            'payment_status' => $data['payment_status'] ?? 'unpaid',
            'subtotal' => $data['subtotal'] ?? $data['total'] ?? 0,
            'discount' => $data['discount'] ?? 0,
            'tax' => $data['tax'] ?? 0,
            'total' => $data['total'] ?? 0,
            'issued_at' => $data['issued_at'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}