<?php

namespace App\Services;

use App\Models\Customer;
use RuntimeException;

class MigrationCustomerResolver
{
    public function resolve(int $tenantId, string $name): Customer
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException(
                'Customer name is required to resolve an invoice customer.'
            );
        }

        $customer = Customer::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (!$customer) {
            throw new RuntimeException(
                "Customer '{$name}' could not be found for this tenant."
            );
        }

        return $customer;
    }
}