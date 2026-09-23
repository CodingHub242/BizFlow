<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Tenant;
use App\InventoryMovementType;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

public function test_authenticated_user_can_list_inventory_for_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 25,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'catalog_item_id' => $catalogItem->id,
            'branch_id' => $branch->id,
        ]);
}

public function test_inventory_list_does_not_include_other_tenants(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 25,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $otherCatalogItem = CatalogItem::factory()->create([
        'tenant_id' => $otherTenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'catalog_item_id' => $otherCatalogItem->id,
        'quantity' => 100,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'catalog_item_id' => $catalogItem->id,
            'branch_id' => $branch->id,
            'quantity' => '25.000',
        ])
        ->assertJsonMissing([
            'catalog_item_id' => $otherCatalogItem->id,
            'branch_id' => $otherBranch->id,
        ]);
}

public function test_unauthenticated_user_cannot_list_inventory(): void
{
    $response = $this->getJson('/api/inventory');

    $response->assertStatus(401);
}

public function test_authenticated_user_can_view_inventory_from_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 50,
        'reorder_level' => 10,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/inventory/{$inventory->id}");

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonFragment([
            'id' => $inventory->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => '50.000',
            'reorder_level' => '10.000',
        ]);
}
public function test_user_cannot_view_inventory_from_another_tenant(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $otherCatalogItem = CatalogItem::factory()->create([
        'tenant_id' => $otherTenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $otherInventory = Inventory::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'catalog_item_id' => $otherCatalogItem->id,
        'quantity' => 100,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/inventory/{$otherInventory->id}");

    $response->assertStatus(404);
}
public function test_inventory_resource_does_not_expose_tenant_id(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 25,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/inventory/{$inventory->id}");

    $response
        ->assertStatus(200)
        ->assertJsonMissing([
            'tenant_id' => $tenant->id,
        ]);
}
public function test_authenticated_user_can_filter_inventory_by_branch(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $otherCatalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 25,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $otherBranch->id,
        'catalog_item_id' => $otherCatalogItem->id,
        'quantity' => 50,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/inventory?branch_id={$branch->id}");

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
        ])
        ->assertJsonMissing([
            'branch_id' => $otherBranch->id,
            'catalog_item_id' => $otherCatalogItem->id,
        ]);
}
public function test_inventory_branch_filter_rejects_invalid_value(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?branch_id=abc');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_id']);
}
public function test_inventory_branch_filter_cannot_access_another_tenants_branch(): void
{
    $tenant = Tenant::factory()->create();

    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $otherTenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 100,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/inventory?branch_id={$otherBranch->id}");

    $response
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');
}
public function test_authenticated_user_can_filter_inventory_by_catalog_item(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $otherCatalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 25,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $otherCatalogItem->id,
        'quantity' => 50,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/inventory?catalog_item_id={$catalogItem->id}");

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'catalog_item_id' => $catalogItem->id,
        ])
        ->assertJsonMissing([
            'catalog_item_id' => $otherCatalogItem->id,
        ]);
}
public function test_inventory_catalog_item_filter_cannot_access_another_tenants_item(): void
{
    $tenant = Tenant::factory()->create();

    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $otherCatalogItem = CatalogItem::factory()->create([
        'tenant_id' => $otherTenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'catalog_item_id' => $otherCatalogItem->id,
        'quantity' => 100,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/inventory?catalog_item_id={$otherCatalogItem->id}");

    $response
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');
}
public function test_inventory_catalog_item_filter_rejects_invalid_value(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?catalog_item_id=abc');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['catalog_item_id']);
}
public function test_authenticated_user_can_filter_inventory_by_branch_and_catalog_item(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $otherCatalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    // Exact match — should be returned.
    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 25,
    ]);

    // Same branch, different item — should not be returned.
    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $otherCatalogItem->id,
        'quantity' => 50,
    ]);

    // Same item, different branch — should not be returned.
    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $otherBranch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 75,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/inventory?branch_id={$branch->id}&catalog_item_id={$catalogItem->id}"
        );

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => '25.000',
        ]);
}
public function test_authenticated_user_can_paginate_inventory(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItems = CatalogItem::factory()
        ->count(3)
        ->create([
            'tenant_id' => $tenant->id,
            'type' => 'product',
            'track_inventory' => true,
        ]);

    foreach ($catalogItems as $catalogItem) {
        Inventory::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 25,
        ]);
    }

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?per_page=2');

    $response
        ->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2);
}
public function test_inventory_pagination_rejects_invalid_per_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?per_page=101');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['per_page']);
}
public function test_inventory_pagination_remains_tenant_isolated(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $catalogItems = CatalogItem::factory()
        ->count(2)
        ->create([
            'tenant_id' => $tenant->id,
            'type' => 'product',
            'track_inventory' => true,
        ]);

    $otherCatalogItem = CatalogItem::factory()->create([
        'tenant_id' => $otherTenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    foreach ($catalogItems as $catalogItem) {
        Inventory::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 25,
        ]);
    }

    Inventory::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'catalog_item_id' => $otherCatalogItem->id,
        'quantity' => 999,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?per_page=1&page=2');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonMissing([
            'quantity' => '999.000',
        ]);
}
public function test_inventory_uses_default_pagination_size(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItems = CatalogItem::factory()
        ->count(16)
        ->create([
            'tenant_id' => $tenant->id,
            'type' => 'product',
            'track_inventory' => true,
        ]);

    foreach ($catalogItems as $catalogItem) {
        Inventory::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 25,
        ]);
    }

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory');

    $response
        ->assertStatus(200)
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 16)
        ->assertJsonPath('meta.last_page', 2);
}
public function test_inventory_pagination_rejects_invalid_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?page=0');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['page']);
}
public function test_authenticated_user_can_paginate_empty_inventory(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?per_page=15');

    $response
        ->assertStatus(200)
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 0);
}
public function test_authenticated_user_can_view_inventory_details(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 75,
        'reorder_level' => 10,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/inventory/{$inventory->id}");

    $response
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $inventory->id)
        ->assertJsonPath('data.branch_id', $branch->id)
        ->assertJsonPath('data.catalog_item_id', $catalogItem->id)
        ->assertJsonPath('data.quantity', '75.000')
        ->assertJsonPath('data.reorder_level', '10.000');
}
public function test_unauthenticated_user_cannot_view_inventory_details(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 25,
    ]);

    $response = $this
        ->getJson("/api/inventory/{$inventory->id}");

    $response->assertStatus(401);
}
public function test_inventory_branch_filter_rejects_non_integer_value(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?branch_id=1.5');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_id']);
}
public function test_inventory_catalog_item_filter_rejects_non_integer_value(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?catalog_item_id=1.5');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['catalog_item_id']);
}
public function test_inventory_pagination_rejects_zero_per_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?per_page=0');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['per_page']);
}
public function test_inventory_returns_empty_data_for_page_beyond_last_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 25,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?per_page=15&page=2');

    $response
        ->assertStatus(200)
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.total', 1);
}
public function test_inventory_accepts_maximum_per_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?per_page=100');

    $response
        ->assertStatus(200)
        ->assertJsonPath('meta.per_page', 100);
}
public function test_inventory_filters_work_with_pagination(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItems = CatalogItem::factory()
        ->count(3)
        ->create([
            'tenant_id' => $tenant->id,
            'type' => 'product',
            'track_inventory' => true,
        ]);

    foreach ($catalogItems as $catalogItem) {
        Inventory::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 25,
        ]);
    }

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/inventory?branch_id={$branch->id}"
            . "&per_page=2"
        );

    $response
        ->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2);
}
public function test_inventory_combined_filters_work_with_pagination(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $otherCatalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 25,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $otherBranch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 50,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $otherCatalogItem->id,
        'quantity' => 75,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/inventory?branch_id={$branch->id}"
            . "&catalog_item_id={$catalogItem->id}"
            . "&per_page=1"
        );

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonFragment([
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => '25.000',
        ]);
}
public function test_inventory_is_ordered_by_latest(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $firstItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $secondItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $firstInventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $firstItem->id,
        'quantity' => 10,
    ]);

    $secondInventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $secondItem->id,
        'quantity' => 20,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?per_page=2');

    $response
        ->assertStatus(200)
        ->assertJsonPath('data.0.id', $secondInventory->id)
        ->assertJsonPath('data.1.id', $firstInventory->id);
}
public function test_inventory_returns_all_records_with_maximum_per_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItems = CatalogItem::factory()
        ->count(3)
        ->create([
            'tenant_id' => $tenant->id,
            'type' => 'product',
            'track_inventory' => true,
        ]);

    foreach ($catalogItems as $catalogItem) {
        Inventory::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 25,
        ]);
    }

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?per_page=100');

    $response
        ->assertStatus(200)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.per_page', 100)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 1);
}
public function test_inventory_can_return_second_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItems = CatalogItem::factory()
        ->count(3)
        ->create([
            'tenant_id' => $tenant->id,
            'type' => 'product',
            'track_inventory' => true,
        ]);

    foreach ($catalogItems as $catalogItem) {
        Inventory::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 25,
        ]);
    }

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?per_page=2&page=2');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2);
}
public function test_inventory_pagination_rejects_non_integer_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?page=abc');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['page']);
}
public function test_inventory_filters_reject_negative_ids(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?branch_id=-1&catalog_item_id=-1');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'branch_id',
            'catalog_item_id',
        ]);
}
public function test_inventory_filters_reject_zero_ids(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?branch_id=0&catalog_item_id=0');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'branch_id',
            'catalog_item_id',
        ]);
}
public function test_inventory_filters_accept_minimum_valid_ids(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'id' => 1,
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'id' => 1,
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 25,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?branch_id=1&catalog_item_id=1');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.branch_id', 1)
        ->assertJsonPath('data.0.catalog_item_id', 1);
}
public function test_inventory_accepts_minimum_per_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItems = CatalogItem::factory()
        ->count(2)
        ->create([
            'tenant_id' => $tenant->id,
            'type' => 'product',
            'track_inventory' => true,
        ]);

    foreach ($catalogItems as $catalogItem) {
        Inventory::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 25,
        ]);
    }

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory?per_page=1');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.last_page', 2);
}
public function test_inventory_resource_exposes_only_public_fields(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 25,
        'reorder_level' => 5,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/inventory/{$inventory->id}");

    $response
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'branch_id',
                'catalog_item_id',
                'quantity',
                'reorder_level',
                'created_at',
                'updated_at',
            ],
        ])
        ->assertJsonMissing([
            'tenant_id' => $tenant->id,
            'deleted_at' => null,
        ]);
}
public function test_inventory_combined_filters_and_pagination_remain_tenant_isolated(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $catalogItems = CatalogItem::factory()
        ->count(2)
        ->create([
            'tenant_id' => $tenant->id,
            'type' => 'product',
            'track_inventory' => true,
        ]);

    $otherCatalogItem = CatalogItem::factory()->create([
        'tenant_id' => $otherTenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    foreach ($catalogItems as $catalogItem) {
        Inventory::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 25,
        ]);
    }

    Inventory::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'catalog_item_id' => $otherCatalogItem->id,
        'quantity' => 999,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/inventory?branch_id={$branch->id}"
            . "&per_page=1"
            . "&page=2"
        );

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonMissing([
            'quantity' => '999.000',
        ]);
}
public function test_authenticated_user_can_receive_stock(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 50,
            'notes' => 'Initial stock received',
        ]);

    $response
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.branch_id', $branch->id)
        ->assertJsonPath('data.catalog_item_id', $catalogItem->id)
        ->assertJsonPath('data.quantity', '50.000');

    $this->assertDatabaseHas('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 50,
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => 'purchase',
        'quantity' => 50,
        'notes' => 'Initial stock received',
    ]);
}
public function test_unauthenticated_user_cannot_receive_stock(): void
{
    $branch = Branch::factory()->create();

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $branch->tenant_id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this->postJson('/api/inventory/receive', [
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 50,
    ]);

    $response->assertStatus(401);

    $this->assertDatabaseCount('inventories', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
}
public function test_user_cannot_receive_stock_for_another_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $branchB = Branch::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $catalogItemB = CatalogItem::factory()->create([
        'tenant_id' => $tenantB->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($userA)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branchB->id,
            'catalog_item_id' => $catalogItemB->id,
            'quantity' => 50,
        ]);

    $response->assertStatus(404);

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenantB->id,
        'branch_id' => $branchB->id,
        'catalog_item_id' => $catalogItemB->id,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenantB->id,
        'branch_id' => $branchB->id,
        'catalog_item_id' => $catalogItemB->id,
    ]);
}
public function test_cannot_receive_stock_for_non_inventory_service(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $service = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'service',
        'track_inventory' => false,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $service->id,
            'quantity' => 10,
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonPath(
            'message',
            'This catalog item does not track inventory.'
        );

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $service->id,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $service->id,
    ]);
}
public function test_receive_stock_rejects_zero_quantity(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 0,
        ]);

    $response->assertStatus(422);

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
    ]);
}
public function test_receive_stock_rejects_negative_quantity(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => -10,
        ]);

    $response->assertStatus(422);

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
    ]);
}
public function test_receive_stock_rejects_nonexistent_branch(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => 999999,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 10,
        ]);

    $response->assertStatus(404);

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenant->id,
        'catalog_item_id' => $catalogItem->id,
    ]);
}
public function test_receive_stock_rejects_nonexistent_catalog_item(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => 999999,
            'quantity' => 10,
        ]);

    $response->assertStatus(404);

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);
}
public function test_authenticated_user_can_receive_additional_stock(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 50,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 25,
        ]);

    $response
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.quantity', '75.000');

    $this->assertDatabaseHas('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 75,
    ]);

    $this->assertDatabaseCount('inventories', 1);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => 'purchase',
        'quantity' => 25,
    ]);
}
public function test_receive_stock_records_reference_information(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 20,
            'reference_type' => 'purchase',
            'reference_id' => 12345,
        ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => 'purchase',
        'quantity' => 20,
        'reference_type' => 'purchase',
        'reference_id' => 12345,
    ]);
}
public function test_receive_stock_rejects_invalid_reference_id(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 10,
            'reference_type' => 'purchase',
            'reference_id' => 0,
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reference_id']);

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
    ]);
}
public function test_receive_stock_rejects_reference_type_longer_than_255_characters(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 10,
            'reference_type' => str_repeat('a', 256),
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reference_type']);

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
    ]);
}
public function test_receive_stock_records_notes(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 15,
            'notes' => 'Received from supplier delivery.',
        ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => 'purchase',
        'quantity' => 15,
        'notes' => 'Received from supplier delivery.',
    ]);
}
public function test_receive_stock_works_without_optional_fields(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 10,
        ]);

    $response
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.quantity', '10.000');

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => 'purchase',
        'quantity' => 10,
        'notes' => null,
        'reference_type' => null,
        'reference_id' => null,
    ]);
}
public function test_authenticated_user_can_receive_decimal_stock_quantity(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 2.5,
        ]);

    $response
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.quantity', '2.500');

    $this->assertDatabaseHas('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 2.5,
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => 'purchase',
        'quantity' => 2.5,
    ]);
}
public function test_authenticated_user_can_receive_large_stock_quantity(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 999999999.999,
        ]);

    $response
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.quantity', '999999999.999');

    $this->assertDatabaseHas('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 999999999.999,
    ]);
}
public function test_receive_stock_preserves_existing_reorder_level(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 20,
        'reorder_level' => 10,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 15,
        ]);

    $response
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.quantity', '35.000')
        ->assertJsonPath('data.reorder_level', '10.000');

    $this->assertDatabaseHas('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 35,
        'reorder_level' => 10,
    ]);
}
public function test_receive_stock_creates_new_movement_without_removing_history(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 20,
    ]);

    InventoryMovement::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => 'opening',
        'quantity' => 20,
        'notes' => 'Opening balance',
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 15,
            'notes' => 'Additional delivery',
        ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => 'opening',
        'quantity' => 20,
        'notes' => 'Opening balance',
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => 'purchase',
        'quantity' => 15,
        'notes' => 'Additional delivery',
    ]);

    $this->assertDatabaseCount('inventory_movements', 2);
}
public function test_user_cannot_receive_stock_for_another_tenants_catalog_item(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $branchA = Branch::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $catalogItemB = CatalogItem::factory()->create([
        'tenant_id' => $tenantB->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($userA)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branchA->id,
            'catalog_item_id' => $catalogItemB->id,
            'quantity' => 25,
        ]);

    $response->assertStatus(404);

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenantA->id,
        'branch_id' => $branchA->id,
        'catalog_item_id' => $catalogItemB->id,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenantA->id,
        'branch_id' => $branchA->id,
        'catalog_item_id' => $catalogItemB->id,
    ]);
}
public function test_receive_stock_rejects_non_string_reference_type(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 10,
            'reference_type' => ['purchase'],
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reference_type']);

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
    ]);
}
public function test_receive_stock_rejects_non_integer_reference_id(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 10,
            'reference_type' => 'purchase',
            'reference_id' => 'not-an-integer',
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reference_id']);

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
    ]);
}
public function test_receive_stock_rejects_non_string_notes(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/receive', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 10,
            'notes' => ['invalid'],
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['notes']);

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
    ]);
}
public function test_authenticated_user_can_increase_stock_with_adjustment(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 20,
        'reorder_level' => 5,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/adjust', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 10,
            'notes' => 'Stock count correction',
        ]);

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'tenant_id' => $tenant->id,
        'quantity' => 30,
        'reorder_level' => 5,
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::ADJUSTMENT->value,
        'quantity' => 10,
        'notes' => 'Stock count correction',
    ]);
}

public function test_stock_adjustment_cannot_create_negative_inventory(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 20,
        'reorder_level' => 5,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/adjust', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => -25,
            'notes' => 'Damaged stock',
        ]);

    $response
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Stock adjustment cannot result in negative inventory.',
        ]);

    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 20,
        'reorder_level' => 5,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::ADJUSTMENT->value,
        'quantity' => -25,
    ]);
}
public function test_authenticated_user_can_decrease_stock_with_adjustment(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 20,
        'reorder_level' => 5,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/adjust', [
            'branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => -7,
            'notes' => 'Damaged stock',
        ]);

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'tenant_id' => $tenant->id,
        'quantity' => 13,
        'reorder_level' => 5,
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::ADJUSTMENT->value,
        'quantity' => -7,
        'notes' => 'Damaged stock',
    ]);
}
public function test_user_cannot_adjust_inventory_for_another_tenant(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $otherCatalogItem = CatalogItem::factory()->create([
        'tenant_id' => $otherTenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'catalog_item_id' => $otherCatalogItem->id,
        'quantity' => 20,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/adjust', [
            'branch_id' => $otherBranch->id,
            'catalog_item_id' => $otherCatalogItem->id,
            'quantity' => 10,
        ]);

    $response->assertStatus(404);

    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'tenant_id' => $otherTenant->id,
        'quantity' => 20,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $otherBranch->id,
        'catalog_item_id' => $otherCatalogItem->id,
        'type' => InventoryMovementType::ADJUSTMENT->value,
        'quantity' => 10,
    ]);
}
public function test_authenticated_user_can_list_inventory_movements_for_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    InventoryMovement::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::PURCHASE,
        'quantity' => 10,
        'notes' => 'Initial stock',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory/movements');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(1, 'data');

    $response->assertJsonPath(
        'data.0.branch_id',
        $branch->id
    );

    $response->assertJsonPath(
        'data.0.catalog_item_id',
        $catalogItem->id
    );

    $response->assertJsonPath(
        'data.0.type',
        InventoryMovementType::PURCHASE->value
    );

    $response->assertJsonPath(
        'data.0.quantity',
        '10.000'
    );
}
public function test_inventory_movements_do_not_include_other_tenants(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $otherCatalogItem = CatalogItem::factory()->create([
        'tenant_id' => $otherTenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    InventoryMovement::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::PURCHASE,
        'quantity' => 10,
    ]);

    InventoryMovement::create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'catalog_item_id' => $otherCatalogItem->id,
        'type' => InventoryMovementType::PURCHASE,
        'quantity' => 50,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/inventory/movements');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath(
            'data.0.catalog_item_id',
            $catalogItem->id
        );

    $response->assertJsonMissing([
        'catalog_item_id' => $otherCatalogItem->id,
    ]);
}
public function test_authenticated_user_can_filter_inventory_movements_by_catalog_item(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $itemOne = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $itemTwo = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    InventoryMovement::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $itemOne->id,
        'type' => InventoryMovementType::PURCHASE,
        'quantity' => 10,
    ]);

    InventoryMovement::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $itemTwo->id,
        'type' => InventoryMovementType::ADJUSTMENT,
        'quantity' => -2,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            '/api/inventory/movements?catalog_item_id=' . $itemOne->id
        );

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath(
            'data.0.catalog_item_id',
            $itemOne->id
        );
}
public function test_authenticated_user_can_filter_inventory_movements_by_branch_and_type(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branchOne = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branchTwo = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    InventoryMovement::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branchOne->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::PURCHASE,
        'quantity' => 10,
    ]);

    InventoryMovement::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branchOne->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::ADJUSTMENT,
        'quantity' => 5,
    ]);

    InventoryMovement::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branchTwo->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::PURCHASE,
        'quantity' => 20,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            '/api/inventory/movements'
            . '?branch_id=' . $branchOne->id
            . '&type=' . InventoryMovementType::ADJUSTMENT->value
        );

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath(
            'data.0.branch_id',
            $branchOne->id
        )
        ->assertJsonPath(
            'data.0.type',
            InventoryMovementType::ADJUSTMENT->value
        );
}
public function test_authenticated_user_can_transfer_stock_between_branches(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $sourceBranch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $destinationBranch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $sourceInventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $sourceBranch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 20,
        'reorder_level' => 5,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/transfer', [
            'from_branch_id' => $sourceBranch->id,
            'to_branch_id' => $destinationBranch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 5,
            'notes' => 'Branch stock transfer',
        ]);

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('inventories', [
        'id' => $sourceInventory->id,
        'tenant_id' => $tenant->id,
        'branch_id' => $sourceBranch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 15,
    ]);

    $this->assertDatabaseHas('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $destinationBranch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 5,
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $sourceBranch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::TRANSFER_OUT->value,
        'quantity' => 5,
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'tenant_id' => $tenant->id,
        'branch_id' => $destinationBranch->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::TRANSFER_IN->value,
        'quantity' => 5,
    ]);
}
public function test_stock_transfer_rejects_insufficient_stock_without_changes(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $sourceBranch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $destinationBranch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $sourceInventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $sourceBranch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 5,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/transfer', [
            'from_branch_id' => $sourceBranch->id,
            'to_branch_id' => $destinationBranch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 10,
        ]);

    $response
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Insufficient stock for transfer.',
        ]);

    $this->assertDatabaseHas('inventories', [
        'id' => $sourceInventory->id,
        'quantity' => 5,
    ]);

    $this->assertDatabaseMissing('inventories', [
        'tenant_id' => $tenant->id,
        'branch_id' => $destinationBranch->id,
        'catalog_item_id' => $catalogItem->id,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenant->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::TRANSFER_OUT->value,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenant->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::TRANSFER_IN->value,
    ]);
}
public function test_user_cannot_transfer_stock_for_another_tenant(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $sourceBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $destinationBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $otherTenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $sourceInventory = Inventory::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $sourceBranch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 20,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/transfer', [
            'from_branch_id' => $sourceBranch->id,
            'to_branch_id' => $destinationBranch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 5,
        ]);

    $response->assertStatus(404);

    $this->assertDatabaseHas('inventories', [
        'id' => $sourceInventory->id,
        'tenant_id' => $otherTenant->id,
        'quantity' => 20,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenant->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::TRANSFER_OUT->value,
        'quantity' => 5,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenant->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::TRANSFER_IN->value,
        'quantity' => 5,
    ]);
}
public function test_stock_transfer_rejects_same_source_and_destination_branch(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'track_inventory' => true,
    ]);

    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'catalog_item_id' => $catalogItem->id,
        'quantity' => 20,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/inventory/transfer', [
            'from_branch_id' => $branch->id,
            'to_branch_id' => $branch->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 5,
        ]);

    $response
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Source and destination branches must be different.',
        ]);

    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 20,
    ]);

    $this->assertDatabaseMissing('inventory_movements', [
        'tenant_id' => $tenant->id,
        'catalog_item_id' => $catalogItem->id,
        'type' => InventoryMovementType::TRANSFER_OUT->value,
        'quantity' => 5,
    ]);
}
}