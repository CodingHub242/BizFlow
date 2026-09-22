<?php

namespace Database\Factories;

use App\CatalogItemType;
use App\Models\CatalogItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class CatalogItemFactory extends Factory
{
    protected $model = CatalogItem::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'category_id' => null,
            'type' => CatalogItemType::PRODUCT,
            'name' => fake()->words(3, true),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####??')),
            'description' => fake()->sentence(),
            'unit' => 'unit',
            'cost_price' => 100,
            'selling_price' => 150,
            'tax_rate' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ];
    }

    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CatalogItemType::SERVICE,
            'unit' => 'service',
            'track_inventory' => false,
        ]);
    }

    public function product(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CatalogItemType::PRODUCT,
            'unit' => 'unit',
            'track_inventory' => true,
        ]);
    }
}