<?php

namespace Database\Factories;

use App\Models\CatalogItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseItem>
 */
class PurchaseItemFactory extends Factory
{
    protected $model = PurchaseItem::class;

    public function definition(): array
    {
        $tenant = Tenant::factory();

        return [
            'tenant_id' => $tenant,

            'purchase_id' => Purchase::factory()
                ->for($tenant),

            'catalog_item_id' => CatalogItem::factory()
                ->for($tenant),

            'quantity' => fake()->randomFloat(3, 1, 100),

            'received_quantity' => 0,

            'unit_price' => fake()->randomFloat(2, 10, 5000),

            'discount' => 0,

            'tax' => 0,

            'line_total' => 0,
        ];
    }
}