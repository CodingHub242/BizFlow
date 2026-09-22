<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'business_type' => fake()->randomElement([
                'retail',
                'service',
                'construction',
                'interior_design',
                'plumbing',
            ]),
            'logo_path' => null,
            'status' => 'active',
        ];
    }
}