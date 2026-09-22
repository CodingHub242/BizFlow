<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),

            'name' => fake()->name(),

            'email' => fake()->unique()->safeEmail(),

            'phone' => fake()->unique()->phoneNumber(),

            'company_name' => fake()->optional()->company(),

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