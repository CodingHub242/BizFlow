<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Inventory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryFactory extends Factory
{
    protected $model = Inventory::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => Branch::factory(),
            'catalog_item_id' => CatalogItem::factory(),
            'quantity' => 0,
            'reorder_level' => 0,
        ];
    }
}