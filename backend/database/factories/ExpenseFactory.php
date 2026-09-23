<?php

namespace Database\Factories;

use App\ExpensePaymentMethod;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => Branch::factory(),
            'category_id' => null,
            'created_by' => User::factory(),
            'amount' => fake()->randomFloat(2, 10, 10000),
            'payment_method' => fake()->randomElement(
                ExpensePaymentMethod::cases()
            ),
            'reference' => fake()->optional()->bothify('EXP-####??'),
            'expense_date' => fake()->date(),
            'description' => fake()->sentence(4),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}