<?php

namespace Database\Factories;

use App\Models\EngageContact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class EngageContactFactory extends Factory
{
    protected $model = EngageContact::class;

    public function definition(): array
    {
        $phone = '024' . fake()->numerify('#######');

        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => null,
            'name' => fake()->name(),
            'phone' => $phone,
            'normalized_phone' => '+233' . substr($phone, 1),
            'email' => fake()->safeEmail(),
            'company_name' => fake()->optional()->company(),
            'source' => 'manual',
            'lifecycle_status' => 'prospect',
            'status' => 'active',
            'notes' => null,
            'first_contacted_at' => now(),
            'last_contacted_at' => now(),
        ];
    }
}
