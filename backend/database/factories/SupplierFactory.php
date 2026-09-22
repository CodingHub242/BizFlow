<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),

            'name' => fake()->name(),

            'company_name' => fake()->optional()->company(),

            'email' => fake()->unique()->safeEmail(),

            'phone' => fake()->unique()->phoneNumber(),

            'address' => fake()->optional()->address(),

            'status' => 'active',

            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => 'inactive',
        ]);
    }
}