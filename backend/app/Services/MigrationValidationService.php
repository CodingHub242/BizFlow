<?php

namespace App\Services;


class MigrationValidationService
{
    
    public function validateRow(string $entityType, array $row): void
    {
        
        if ($entityType === 'expenses') 
        {
            if (
                !isset($row['amount']) ||
                !is_numeric($row['amount'])
            ) {
                throw new \InvalidArgumentException(
                    'Expense amount must be a valid number.'
                );
            }

            if (
                !isset($row['expense_date']) ||
                strtotime($row['expense_date']) === false
            ) {
                throw new \InvalidArgumentException(
                    'Expense date must be a valid date.'
                );
            }
        }

        if ($entityType === 'invoices') 
        {
            if (
                !isset($row['total']) ||
                !is_numeric($row['total'])
            ) {
                throw new \InvalidArgumentException(
                    'Invoice total must be a valid number.'
                );
            }

            if (
                !isset($row['issued_at']) ||
                strtotime($row['issued_at']) === false
            ) {
                throw new \InvalidArgumentException(
                    'Invoice issued date must be a valid date.'
                );
            }

            if (
                isset($row['due_at']) &&
                $row['due_at'] !== null &&
                strtotime($row['due_at']) === false
            ) {
                throw new \InvalidArgumentException(
                    'Invoice due date must be a valid date.'
                );
            }
        }

        if ($entityType === 'catalog_items') 
        {
            if (
                !isset($row['selling_price']) ||
                !is_numeric($row['selling_price'])
            ) {
                throw new \InvalidArgumentException(
                    'Catalog item selling price must be a valid number.'
                );
            }

            if (
                !isset($row['cost_price']) ||
                !is_numeric($row['cost_price'])
            ) {
                throw new \InvalidArgumentException(
                    'Catalog item cost price must be a valid number.'
                );
            }
        }

        if ($entityType === 'payments') 
        {
            if (
                !isset($row['amount']) ||
                !is_numeric($row['amount'])
            ) {
                throw new \InvalidArgumentException(
                    'Payment amount must be a valid number.'
                );
            }

            if (
                !isset($row['payment_date']) ||
                !is_date($row['payment_date'])
            ) {
                throw new \InvalidArgumentException(
                    'Payment date must be a valid date.'
                );
            }
        }

       

        
    }

   public function validateRowWithErrors(string $entityType,array $row): array 
   {
        $errors = [];

        switch ($entityType) {
            case 'customers':
                if (
                    !isset($row['name']) ||
                    trim((string) $row['name']) === ''
                ) {
                    $errors[] = 'Customer name is required.';
                }

                if (
                    isset($row['email']) &&
                    $row['email'] !== '' &&
                    !filter_var($row['email'], FILTER_VALIDATE_EMAIL)
                ) {
                    $errors[] = 'Customer email must be valid.';
                }

                break;

            case 'suppliers':
                if (
                    !isset($row['name']) ||
                    trim((string) $row['name']) === ''
                ) {
                    $errors[] = 'Supplier name is required.';
                }

                if (
                    isset($row['email']) &&
                    $row['email'] !== '' &&
                    !filter_var($row['email'], FILTER_VALIDATE_EMAIL)
                ) {
                    $errors[] = 'Supplier email must be valid.';
                }

                break;

            case 'catalog_items':
                if (
                    !isset($row['name']) ||
                    trim((string) $row['name']) === ''
                ) {
                    $errors[] = 'Catalog item name is required.';
                }

                if (
                    !isset($row['selling_price']) ||
                    !is_numeric($row['selling_price'])
                ) {
                    $errors[] = 'Catalog item selling price must be a valid number.';
                }

                if (
                    !isset($row['cost_price']) ||
                    !is_numeric($row['cost_price'])
                ) {
                    $errors[] = 'Catalog item cost price must be a valid number.';
                }

                break;

            case 'invoices':
                if (
                    !isset($row['invoice_number']) ||
                    trim((string) $row['invoice_number']) === ''
                ) {
                    $errors[] = 'Invoice number is required.';
                }

                if (
                    !isset($row['total']) ||
                    !is_numeric($row['total'])
                ) {
                    $errors[] = 'Invoice total must be a valid number.';
                }

                if (
                    !isset($row['issued_at']) ||
                    strtotime((string) $row['issued_at']) === false
                ) {
                    $errors[] = 'Invoice issued date must be a valid date.';
                }

                if (
                    isset($row['due_at']) &&
                    $row['due_at'] !== null &&
                    $row['due_at'] !== '' &&
                    strtotime((string) $row['due_at']) === false
                ) {
                    $errors[] = 'Invoice due date must be a valid date.';
                }

                break;

            case 'expenses':
                if (
                    !isset($row['amount']) ||
                    !is_numeric($row['amount'])
                ) {
                    $errors[] = 'Expense amount must be a valid number.';
                }

                if (
                    !isset($row['expense_date']) ||
                    strtotime((string) $row['expense_date']) === false
                ) {
                    $errors[] = 'Expense date must be a valid date.';
                }

                break;

            case 'payments':
                if (
                    !isset($row['amount']) ||
                    !is_numeric($row['amount'])
                ) {
                    $errors[] = 'Payment amount must be a valid number.';
                }

                if (
                    !isset($row['paid_at']) ||
                    strtotime((string) $row['paid_at']) === false
                ) {
                    $errors[] = 'Payment date must be a valid date.';
                }

                break;
        }

        return $errors;
    }

    public function validateRows(string $entityType, array $rows): array
    {
        $totalRows = count($rows);
        $validRows = 0;
        $invalidRows = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $messages = $this->validateRowWithErrors($entityType, $row);

            if ($messages === []) {
                $validRows++;
                continue;
            }

            $invalidRows++;

            $errors[] = [
                'row' => $index + 1,
                'messages' => $messages,
            ];
        }

        return [
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'errors' => $errors,
        ];
    }

    

}