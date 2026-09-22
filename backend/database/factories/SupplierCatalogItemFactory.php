<?php

namespace Database\Factories;

use App\Models\CatalogItem;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierCatalogItem>
 */
class SupplierCatalogItemFactory extends Factory
{
    protected $model = SupplierCatalogItem::class;

    public function definition(): array
    {
        $tenant = Tenant::factory();

        return [
            'tenant_id' => $tenant,

            'supplier_id' => Supplier::factory()
                ->for($tenant),

            'catalog_item_id' => CatalogItem::factory()
                ->for($tenant),

            'supplier_sku' => fake()->optional()->bothify('SUP-####-???'),

            'purchase_price' => fake()->randomFloat(2, 10, 5000),

            'minimum_order_quantity' => fake()->randomFloat(3, 1, 100),

            'lead_time_days' => fake()->numberBetween(1, 30),

            'is_preferred' => false,

            'is_active' => true,
        ];
    }

    public function preferred(): static
    {
        return $this->state(fn () => [
            'is_preferred' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}