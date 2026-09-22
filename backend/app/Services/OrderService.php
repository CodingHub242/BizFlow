<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function create(array $data): Order
    {
        $tenantId = $data['tenant_id'];

        $branch = \App\Models\Branch::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($data['branch_id']);

        $customerId = $data['customer_id'] ?? null;

        if ($customerId !== null) {
            $customer = Customer::query()
                ->where('tenant_id', $tenantId)
                ->find($customerId);

            if (!$customer) {
                throw ValidationException::withMessages([
                    'customer_id' => [
                        'The selected customer does not belong to this tenant.',
                    ],
                ]);
            }
        }

        return Order::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branch->id,
            'created_by' => $data['created_by'],
            'customer_id' => $customerId,
            'order_number' => $data['order_number'],
            'status' => $data['status'] ?? 'draft',
            'payment_status' => $data['payment_status'] ?? 'unpaid',
            'subtotal' => $data['subtotal'] ?? 0,
            'discount' => $data['discount'] ?? 0,
            'tax' => $data['tax'] ?? 0,
            'total' => $data['total'] ?? 0,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}