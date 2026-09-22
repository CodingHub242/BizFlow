<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    public function definition(): array
    {
        $tenant = Tenant::factory();

        return [
            'tenant_id' => $tenant,

            'branch_id' => Branch::factory()
                ->for($tenant),

            'supplier_id' => Supplier::factory()
                ->for($tenant),

            'created_by' => User::factory()
                ->for($tenant),

            'purchase_number' => 'PUR-' . fake()->unique()->numerify('######'),

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