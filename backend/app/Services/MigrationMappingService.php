<?php

namespace App\Services;

use App\Models\MigrationMapping;
use App\Models\MigrationSession;

class MigrationMappingService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function generate(string $entityType, array $headers): array
    {
        $mapping = [];

        foreach ($headers as $header) {
            $normalized = strtolower(trim($header));

            $mapping[$header] = match ($entityType) {
                'customers' => match ($normalized) {
                    'customer' => 'name',
                    'email' => 'email',
                    'phone' => 'phone',
                    'company' => 'company_name',
                    default => null,
                },

                'suppliers' => match ($normalized) {
                    'vendor' => 'name',
                    'email' => 'email',
                    'phone' => 'phone',
                    'company' => 'company_name',
                    default => null,
                },

                'catalog_items' => match ($normalized) {
                    'product/service name' => 'name',
                    'sku' => 'sku',
                    'description' => 'description',
                    'sales price' => 'selling_price',
                    'cost' => 'cost_price',
                    default => null,
                },

                'invoices' => match ($normalized) {
                    'invoice number' => 'invoice_number',
                    'customer' => 'customer_id',
                    'invoice date' => 'issued_at',
                    'due date' => 'due_at',
                    'amount' => 'total',
                    default => null,
                },

                'expenses' => match ($normalized) {
                    'date' => 'expense_date',
                    'payee' => 'description',
                    'category' => 'category_id',
                    'amount' => 'amount',
                    'payment method' => 'payment_method',
                    default => null,
                },

                'payments' => match ($normalized) {
                    'payment date' => 'paid_at',
                    'customer' => 'customer_id',
                    'invoice number' => 'invoice_id',
                    'amount' => 'amount',
                    'payment method' => 'method',
                    default => null,
                },

                default => null,
            };
        }

        return $mapping;
    }

   public function create(MigrationSession $session,string $entityType,array $fieldMapping): MigrationMapping 
   {
        return MigrationMapping::updateOrCreate(
            [
                'tenant_id' => $session->tenant_id,
                'migration_session_id' => $session->id,
                'entity_type' => $entityType,
            ],
            [
                'field_mapping' => $fieldMapping,
            ]
        );
    }

    public function validate(string $entityType, array $mapping): void
    {
        $allowedFields = match ($entityType) {
            'customers' => [
                'name',
                'email',
                'phone',
                'company_name',
            ],

            'suppliers' => [
                'name',
                'email',
                'phone',
                'company_name',
            ],

            'catalog_items' => [
                'name',
                'sku',
                'description',
                'selling_price',
                'cost_price',
            ],

            'invoices' => [
                'invoice_number',
                'customer_id',
                'issued_at',
                'due_at',
                'total',
            ],

            'expenses' => [
                'expense_date',
                'description',
                'category_id',
                'amount',
                'payment_method',
            ],

            'payments' => [
                'paid_at',
                'customer_id',
                'invoice_id',
                'amount',
                'method',
            ],

            default => [],
        };

        foreach ($mapping as $targetField) 
        {
            if (
                $targetField !== null &&
                !in_array($targetField, $allowedFields, true)
            ) {
                throw new \InvalidArgumentException(
                    "Invalid target field \"{$targetField}\" for {$entityType}."
                );
            }
        }

        if (in_array($entityType, ['customers', 'suppliers', 'catalog_items'], true)) {
            $label = match ($entityType) {
                'customers' => 'Customer',
                'suppliers' => 'Supplier',
                'catalog_items' => 'Catalog item',
            };

            if (!in_array('name', $mapping, true)) {
                throw new \InvalidArgumentException(
                    "{$label} mapping must include a name field."
                );
            }

            return;
        }

        if ($entityType === 'invoices') 
        {
            if (!in_array('invoice_number', $mapping, true)) {
                throw new \InvalidArgumentException(
                    'Invoice mapping must include an invoice number field.'
                );
            }
        }

        if ($entityType === 'expenses') 
        {
            if (!in_array('amount', $mapping, true)) {
                throw new \InvalidArgumentException(
                    'Expense mapping must include an amount field.'
                );
            }
        }

        if ($entityType === 'payments') 
        {
            if (!in_array('amount', $mapping, true)) {
                throw new \InvalidArgumentException(
                    'Payment mapping must include an amount field.'
                );
            }
        }
    }

    public function transformRow(string $entityType,array $row,array $mapping) : array
    {
        $this->validate($entityType, $mapping);

        $transformed = [];

        foreach ($mapping as $sourceField => $targetField) {
            if ($targetField === null) {
                continue;
            }

            if (!array_key_exists($sourceField, $row)) {
                continue;
            }

            $transformed[$targetField] = $row[$sourceField];
        }

        return $transformed;
    }
}
