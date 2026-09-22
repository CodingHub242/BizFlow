<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $tenant = Tenant::factory();

        return [
            'tenant_id' => $tenant,
            'branch_id' => Branch::factory()->for($tenant),
            'customer_id' => Customer::factory()->for($tenant),
            'order_id' => null,
            'created_by' => User::factory()->for($tenant),
            'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 1000,
            'discount' => 0,
            'tax' => 0,
            'total' => 1000,
            'issued_at' => now(),
            'due_at' => null,
            'notes' => null,
        ];
    }
}