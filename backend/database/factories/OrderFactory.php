<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $tenant = Tenant::factory();

        return [
            'tenant_id' => $tenant,
            'branch_id' => Branch::factory()->for($tenant),
            'created_by' => User::factory()->for($tenant),
            'customer_id' => null,
            'order_number' => 'ORD-' . fake()->unique()->numerify('######'),
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'notes' => null,
        ];
    }
}