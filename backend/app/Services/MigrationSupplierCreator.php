<?php

namespace App\Services;

use App\Models\Supplier;

class MigrationSupplierCreator
{
    public function create(int $tenantId, array $data): Supplier
    {
        $email = isset($data['email'])
            ? trim((string) $data['email'])
            : null;

        if ($email !== null && $email !== '') {
            $existing = Supplier::query()
                ->where('tenant_id', $tenantId)
                ->where('email', $email)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return Supplier::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'company_name' => $data['company_name'] ?? null,
            'email' => $email ?: null,
            'phone' => $data['phone'] ?? null,
        ]);
    }
}