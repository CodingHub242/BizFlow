<?php

namespace App\Services;

use App\Models\Customer;

class MigrationCustomerCreator
{
    public function create(int $tenantId, array $data): Customer
    {
        $email = isset($data['email'])
            ? trim((string) $data['email'])
            : null;

        if ($email !== null && $email !== '') {
            $existing = Customer::query()
                ->where('tenant_id', $tenantId)
                ->where('email', $email)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return Customer::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => $email ?: null,
            'phone' => $data['phone'] ?? null,
            'company_name' => $data['company_name'] ?? null,
        ]);
    }
}