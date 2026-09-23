<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogItemApiTest extends TestCase
{
    use RefreshDatabase;

public function test_authenticated_user_can_create_product_for_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/catalog-items', [
            'name' => 'Premium Rice',
            'type' => 'product',
            'sku' => 'RICE-001',
            'description' => 'Premium quality rice',
            'unit' => 'bag',
            'cost_price' => 100.00,
            'selling_price' => 130.00,
            'tax_rate' => 0,
            'track_inventory' => true,
        ]);

    $response
        ->assertStatus(201)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('catalog_items', [
        'tenant_id' => $tenant->id,
        'name' => 'Premium Rice',
        'type' => 'product',
        'sku' => 'RICE-001',
        'cost_price' => 100.00,
        'selling_price' => 130.00,
        'track_inventory' => true,
    ]);
}

public function test_unauthenticated_user_cannot_create_product(): void
{
    $response = $this->postJson('/api/catalog-items', [
        'name' => 'Premium Rice',
        'type' => 'product',
        'sku' => 'RICE-001',
        'description' => 'Premium quality rice',
        'unit' => 'bag',
        'cost_price' => 100.00,
        'selling_price' => 130.00,
        'tax_rate' => 0,
        'track_inventory' => true,
    ]);

    $response->assertStatus(401);

    $this->assertDatabaseCount('catalog_items', 0);
}

public function test_product_is_created_under_authenticated_users_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/catalog-items', [
            'name' => 'Office Chair',
            'type' => 'product',
            'sku' => 'CHAIR-001',
            'unit' => 'piece',
            'cost_price' => 250.00,
            'selling_price' => 350.00,
            'tax_rate' => 0,
            'track_inventory' => true,
            'tenant_id' => $tenantB->id,
        ]);

    $response
        ->assertStatus(201)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('catalog_items', [
        'tenant_id' => $tenantA->id,
        'name' => 'Office Chair',
        'sku' => 'CHAIR-001',
    ]);

    $this->assertDatabaseMissing('catalog_items', [
        'tenant_id' => $tenantB->id,
        'name' => 'Office Chair',
        'sku' => 'CHAIR-001',
    ]);
}

public function test_product_creation_requires_name_and_type(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/catalog-items', [
            'sku' => 'INVALID-001',
            'unit' => 'piece',
            'cost_price' => 100.00,
            'selling_price' => 150.00,
            'track_inventory' => true,
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'name',
            'type',
        ]);

    $this->assertDatabaseMissing('catalog_items', [
        'tenant_id' => $tenant->id,
        'sku' => 'INVALID-001',
    ]);
}

public function test_product_creation_rejects_invalid_type(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/catalog-items', [
            'name' => 'Invalid Item',
            'type' => 'invalid',
            'sku' => 'INVALID-002',
            'unit' => 'piece',
            'cost_price' => 100.00,
            'selling_price' => 150.00,
            'tax_rate' => 0,
            'track_inventory' => true,
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'type',
        ]);

    $this->assertDatabaseMissing('catalog_items', [
        'tenant_id' => $tenant->id,
        'sku' => 'INVALID-002',
    ]);
}

public function test_product_creation_rejects_negative_prices(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/catalog-items', [
            'name' => 'Invalid Price Product',
            'type' => 'product',
            'sku' => 'INVALID-003',
            'unit' => 'piece',
            'cost_price' => -100.00,
            'selling_price' => -150.00,
            'tax_rate' => 0,
            'track_inventory' => true,
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'cost_price',
            'selling_price',
        ]);

    $this->assertDatabaseMissing('catalog_items', [
        'tenant_id' => $tenant->id,
        'sku' => 'INVALID-003',
    ]);
}

public function test_product_creation_rejects_negative_tax_rate(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/catalog-items', [
            'name' => 'Invalid Tax Product',
            'type' => 'product',
            'sku' => 'INVALID-004',
            'unit' => 'piece',
            'cost_price' => 100.00,
            'selling_price' => 150.00,
            'tax_rate' => -5,
            'track_inventory' => true,
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'tax_rate',
        ]);

    $this->assertDatabaseMissing('catalog_items', [
        'tenant_id' => $tenant->id,
        'sku' => 'INVALID-004',
    ]);
}

public function test_product_creation_rejects_duplicate_sku_within_same_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Existing Product',
        'type' => 'product',
        'sku' => 'SKU-001',
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/catalog-items', [
            'name' => 'Another Product',
            'type' => 'product',
            'sku' => 'SKU-001',
            'unit' => 'piece',
            'cost_price' => 100.00,
            'selling_price' => 150.00,
            'tax_rate' => 0,
            'track_inventory' => true,
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'sku',
        ]);

    $this->assertDatabaseMissing('catalog_items', [
        'tenant_id' => $tenant->id,
        'name' => 'Another Product',
        'sku' => 'SKU-001',
    ]);
}

public function test_product_creation_allows_same_sku_for_different_tenants(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    CatalogItem::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Product',
        'type' => 'product',
        'sku' => 'SHARED-001',
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/catalog-items', [
            'name' => 'Tenant A Product',
            'type' => 'product',
            'sku' => 'SHARED-001',
            'unit' => 'piece',
            'cost_price' => 100.00,
            'selling_price' => 150.00,
            'tax_rate' => 0,
            'track_inventory' => true,
        ]);

    $response
        ->assertStatus(201)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('catalog_items', [
        'tenant_id' => $tenantA->id,
        'name' => 'Tenant A Product',
        'sku' => 'SHARED-001',
    ]);

    $this->assertDatabaseHas('catalog_items', [
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Product',
        'sku' => 'SHARED-001',
    ]);
}

public function test_authenticated_user_can_create_service_for_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/catalog-items', [
            'name' => 'Website Development',
            'type' => 'service',
            'sku' => 'SERVICE-001',
            'description' => 'Custom website development service',
            'unit' => 'project',
            'cost_price' => 500.00,
            'selling_price' => 1500.00,
            'tax_rate' => 0,
            'track_inventory' => false,
        ]);

    $response
        ->assertStatus(201)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('catalog_items', [
        'tenant_id' => $tenant->id,
        'name' => 'Website Development',
        'type' => 'service',
        'sku' => 'SERVICE-001',
        'track_inventory' => false,
    ]);
}

public function test_service_can_be_created_without_inventory_tracking(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/catalog-items', [
            'name' => 'Consulting Service',
            'type' => 'service',
            'sku' => 'CONSULT-001',
            'unit' => 'hour',
            'cost_price' => 50.00,
            'selling_price' => 150.00,
            'tax_rate' => 0,
            'track_inventory' => false,
        ]);

    $response
        ->assertStatus(201)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('catalog_items', [
        'tenant_id' => $tenant->id,
        'name' => 'Consulting Service',
        'type' => 'service',
        'track_inventory' => false,
    ]);
}

public function test_authenticated_user_can_create_inactive_catalog_item(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/catalog-items', [
            'name' => 'Discontinued Product',
            'type' => 'product',
            'sku' => 'DISC-001',
            'unit' => 'piece',
            'cost_price' => 100.00,
            'selling_price' => 150.00,
            'tax_rate' => 0,
            'track_inventory' => true,
            'is_active' => false,
        ]);

    $response
        ->assertStatus(201)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('catalog_items', [
        'tenant_id' => $tenant->id,
        'name' => 'Discontinued Product',
        'sku' => 'DISC-001',
        'is_active' => false,
    ]);
}

public function test_unauthenticated_user_cannot_list_catalog_items(): void
{
    $response = $this->getJson('/api/catalog-items');

    $response->assertStatus(401);
}

public function test_authenticated_user_can_list_catalog_items_for_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Premium Rice',
        'type' => 'product',
        'sku' => 'RICE-001',
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Website Development',
        'type' => 'service',
        'sku' => 'SERVICE-001',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment([
            'name' => 'Premium Rice',
            'type' => 'product',
        ])
        ->assertJsonFragment([
            'name' => 'Website Development',
            'type' => 'service',
        ]);
}

public function test_catalog_item_list_does_not_include_other_tenants(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Tenant A Product',
        'type' => 'product',
        'sku' => 'A-001',
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Product',
        'type' => 'product',
        'sku' => 'B-001',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Tenant A Product',
            'sku' => 'A-001',
        ])
        ->assertJsonMissing([
            'name' => 'Tenant B Product',
            'sku' => 'B-001',
        ]);
}

public function test_catalog_item_resource_does_not_expose_tenant_id(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Private Product',
        'type' => 'product',
        'sku' => 'PRIVATE-001',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items');

    $response
        ->assertStatus(200)
        ->assertJsonMissing([
            'tenant_id' => $tenant->id,
        ]);
}
public function test_authenticated_user_can_view_catalog_item_from_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Premium Rice',
        'type' => 'product',
        'sku' => 'RICE-002',
        'cost_price' => 100.00,
        'selling_price' => 130.00,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/catalog-items/{$catalogItem->id}");

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonFragment([
            'id' => $catalogItem->id,
            'name' => 'Premium Rice',
            'type' => 'product',
            'sku' => 'RICE-002',
        ]);
}

public function test_user_cannot_view_catalog_item_from_another_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Private Product',
        'type' => 'product',
        'sku' => 'PRIVATE-002',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/catalog-items/{$catalogItem->id}");

    $response->assertStatus(404);
}
public function test_unauthenticated_user_cannot_view_catalog_item(): void
{
    $tenant = Tenant::factory()->create();

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Private Product',
        'type' => 'product',
        'sku' => 'PRIVATE-003',
    ]);

    $response = $this->getJson("/api/catalog-items/{$catalogItem->id}");

    $response->assertStatus(401);
}
public function test_authenticated_user_can_update_catalog_item_from_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Old Product Name',
        'type' => 'product',
        'sku' => 'UPDATE-001',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/catalog-items/{$catalogItem->id}", [
            'name' => 'Updated Product Name',
            'cost_price' => 120.00,
            'selling_price' => 180.00,
        ]);

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonFragment([
            'name' => 'Updated Product Name',
        ]);

    $this->assertDatabaseHas('catalog_items', [
        'id' => $catalogItem->id,
        'tenant_id' => $tenant->id,
        'name' => 'Updated Product Name',
        'cost_price' => 120.00,
        'selling_price' => 180.00,
    ]);
}

public function test_user_cannot_update_catalog_item_from_another_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Product',
        'type' => 'product',
        'sku' => 'B-UPDATE-001',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/catalog-items/{$catalogItem->id}", [
            'name' => 'Hacked Product',
            'cost_price' => 1.00,
            'selling_price' => 2.00,
        ]);

    $response->assertStatus(404);

    $this->assertDatabaseHas('catalog_items', [
        'id' => $catalogItem->id,
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Product',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ]);
}

public function test_unauthenticated_user_cannot_update_catalog_item(): void
{
    $tenant = Tenant::factory()->create();

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Protected Product',
        'type' => 'product',
        'sku' => 'PROTECTED-001',
    ]);

    $response = $this->putJson("/api/catalog-items/{$catalogItem->id}", [
        'name' => 'Unauthorized Update',
    ]);

    $response->assertStatus(401);

    $this->assertDatabaseHas('catalog_items', [
        'id' => $catalogItem->id,
        'tenant_id' => $tenant->id,
        'name' => 'Protected Product',
    ]);
}
public function test_catalog_item_update_rejects_negative_prices(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Existing Product',
        'type' => 'product',
        'sku' => 'UPDATE-PRICE-001',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/catalog-items/{$catalogItem->id}", [
            'cost_price' => -50.00,
            'selling_price' => -75.00,
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'cost_price',
            'selling_price',
        ]);

    $this->assertDatabaseHas('catalog_items', [
        'id' => $catalogItem->id,
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ]);
}

public function test_catalog_item_update_rejects_negative_tax_rate(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Existing Product',
        'type' => 'product',
        'sku' => 'UPDATE-TAX-001',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
        'tax_rate' => 10.00,
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/catalog-items/{$catalogItem->id}", [
            'tax_rate' => -5.00,
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'tax_rate',
        ]);

    $this->assertDatabaseHas('catalog_items', [
        'id' => $catalogItem->id,
        'tax_rate' => 10.00,
    ]);
}
public function test_catalog_item_update_rejects_duplicate_sku_within_same_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Existing Product',
        'type' => 'product',
        'sku' => 'EXISTING-001',
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Product To Update',
        'type' => 'product',
        'sku' => 'UPDATE-001',
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/catalog-items/{$catalogItem->id}", [
            'sku' => 'EXISTING-001',
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'sku',
        ]);

    $this->assertDatabaseHas('catalog_items', [
        'id' => $catalogItem->id,
        'sku' => 'UPDATE-001',
    ]);
}

public function test_catalog_item_update_allows_existing_sku_to_remain_unchanged(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Original Product',
        'type' => 'product',
        'sku' => 'KEEP-001',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/catalog-items/{$catalogItem->id}", [
            'name' => 'Renamed Product',
            'sku' => 'KEEP-001',
        ]);

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('catalog_items', [
        'id' => $catalogItem->id,
        'tenant_id' => $tenant->id,
        'name' => 'Renamed Product',
        'sku' => 'KEEP-001',
    ]);
}
public function test_unauthenticated_user_cannot_delete_catalog_item(): void
{
    $tenant = Tenant::factory()->create();

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Protected Product',
        'type' => 'product',
        'sku' => 'DELETE-001',
    ]);

    $response = $this->deleteJson("/api/catalog-items/{$catalogItem->id}");

    $response->assertStatus(401);

    $this->assertDatabaseHas('catalog_items', [
        'id' => $catalogItem->id,
        'tenant_id' => $tenant->id,
        'name' => 'Protected Product',
        'deleted_at' => null,
    ]);
}
public function test_authenticated_user_can_delete_catalog_item_from_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Product To Delete',
        'type' => 'product',
        'sku' => 'DELETE-002',
    ]);

    $response = $this
        ->actingAs($user)
        ->deleteJson("/api/catalog-items/{$catalogItem->id}");

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertSoftDeleted('catalog_items', [
        'id' => $catalogItem->id,
        'tenant_id' => $tenant->id,
        'name' => 'Product To Delete',
    ]);
}
public function test_user_cannot_delete_catalog_item_from_another_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Product',
        'type' => 'product',
        'sku' => 'DELETE-003',
    ]);

    $response = $this
        ->actingAs($user)
        ->deleteJson("/api/catalog-items/{$catalogItem->id}");

    $response->assertStatus(404);

    $this->assertDatabaseHas('catalog_items', [
        'id' => $catalogItem->id,
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Product',
        'deleted_at' => null,
    ]);
}

public function test_unauthenticated_user_cannot_restore_deleted_catalog_item(): void
{
    $tenant = Tenant::factory()->create();

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Deleted Product',
        'type' => 'product',
        'sku' => 'RESTORE-001',
    ]);

    $catalogItem->delete();

    $response = $this->postJson(
        "/api/catalog-items/{$catalogItem->id}/restore"
    );

    $response->assertStatus(401);

    $this->assertSoftDeleted('catalog_items', [
        'id' => $catalogItem->id,
        'tenant_id' => $tenant->id,
    ]);
}

public function test_authenticated_user_can_restore_deleted_catalog_item_from_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Restorable Product',
        'type' => 'product',
        'sku' => 'RESTORE-002',
    ]);

    $catalogItem->delete();

    $response = $this
        ->actingAs($user)
        ->postJson("/api/catalog-items/{$catalogItem->id}/restore");

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('catalog_items', [
        'id' => $catalogItem->id,
        'tenant_id' => $tenant->id,
        'name' => 'Restorable Product',
        'deleted_at' => null,
    ]);
}
public function test_user_cannot_restore_catalog_item_from_another_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Deleted Product',
        'type' => 'product',
        'sku' => 'RESTORE-003',
    ]);

    $catalogItem->delete();

    $response = $this
        ->actingAs($user)
        ->postJson("/api/catalog-items/{$catalogItem->id}/restore");

    $response->assertStatus(404);

    $this->assertSoftDeleted('catalog_items', [
        'id' => $catalogItem->id,
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Deleted Product',
    ]);
}

public function test_authenticated_user_can_search_catalog_items(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Premium Rice',
        'type' => 'product',
        'sku' => 'RICE-100',
        'description' => 'Premium imported rice',
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Office Chair',
        'type' => 'product',
        'sku' => 'CHAIR-100',
        'description' => 'Ergonomic office chair',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?search=Rice');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Premium Rice',
            'sku' => 'RICE-100',
        ])
        ->assertJsonMissing([
            'name' => 'Office Chair',
            'sku' => 'CHAIR-100',
        ]);
}

public function test_authenticated_user_can_filter_catalog_items_by_type(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Premium Rice',
        'type' => 'product',
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Business Consultation',
        'type' => 'service',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?type=service');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Business Consultation',
            'type' => 'service',
        ])
        ->assertJsonMissing([
            'name' => 'Premium Rice',
            'type' => 'product',
        ]);
}

public function test_authenticated_user_can_filter_catalog_items_by_active_status(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Active Product',
        'type' => 'product',
        'is_active' => true,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Inactive Product',
        'type' => 'product',
        'is_active' => false,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?is_active=1');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Active Product',
            'is_active' => true,
        ])
        ->assertJsonMissing([
            'name' => 'Inactive Product',
            'is_active' => false,
        ]);
}

public function test_authenticated_user_can_combine_catalog_filters(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Active Product',
        'type' => 'product',
        'is_active' => true,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Inactive Product',
        'type' => 'product',
        'is_active' => false,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Active Service',
        'type' => 'service',
        'is_active' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?type=product&is_active=1');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Active Product',
            'type' => 'product',
            'is_active' => true,
        ])
        ->assertJsonMissing([
            'name' => 'Inactive Product',
        ])
        ->assertJsonMissing([
            'name' => 'Active Service',
        ]);
}
public function test_catalog_type_filter_rejects_invalid_type(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?type=invalid');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
}

public function test_catalog_active_filter_rejects_invalid_value(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?is_active=abc');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['is_active']);
}

public function test_authenticated_user_can_paginate_catalog_items(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?per_page=2');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
    ])
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => [
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);
}
public function test_catalog_pagination_rejects_invalid_per_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?per_page=0');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['per_page']);
}
public function test_catalog_list_uses_default_pagination(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->count(20)->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'meta' => [
                'current_page' => 1,
                'per_page' => 15,
                'total' => 20,
            ],
        ])
        ->assertJsonCount(15, 'data');
}

public function test_authenticated_user_can_navigate_catalog_pages(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->count(20)->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?per_page=15&page=2');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'meta' => [
                'current_page' => 2,
                'last_page' => 2,
                'per_page' => 15,
                'total' => 20,
            ],
        ])
        ->assertJsonCount(5, 'data');
}
public function test_authenticated_user_can_paginate_search_results(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->count(20)->create([
        'tenant_id' => $tenant->id,
        'name' => 'Rice Product',
    ]);

    CatalogItem::factory()->count(5)->create([
        'tenant_id' => $tenant->id,
        'name' => 'Office Chair',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?search=Rice&per_page=10');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'meta' => [
                'current_page' => 1,
                'last_page' => 2,
                'per_page' => 10,
                'total' => 20,
            ],
        ])
        ->assertJsonCount(10, 'data')
        ->assertJsonMissing([
            'name' => 'Office Chair',
        ]);
}

public function test_authenticated_user_can_paginate_type_results(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->count(20)->create([
        'tenant_id' => $tenant->id,
        'type' => 'product',
    ]);

    CatalogItem::factory()->count(5)->create([
        'tenant_id' => $tenant->id,
        'type' => 'service',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?type=product&per_page=10');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'meta' => [
                'current_page' => 1,
                'last_page' => 2,
                'per_page' => 10,
                'total' => 20,
            ],
        ])
        ->assertJsonCount(10, 'data')
        ->assertJsonMissing([
            'type' => 'service',
        ]);
}

public function test_authenticated_user_can_paginate_active_catalog_items(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->count(20)->create([
        'tenant_id' => $tenant->id,
        'is_active' => true,
    ]);

    CatalogItem::factory()->count(5)->create([
        'tenant_id' => $tenant->id,
        'is_active' => false,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?is_active=1&per_page=10');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'meta' => [
                'current_page' => 1,
                'last_page' => 2,
                'per_page' => 10,
                'total' => 20,
            ],
        ])
        ->assertJsonCount(10, 'data')
        ->assertJsonMissing([
            'is_active' => false,
        ]);
}
public function test_catalog_pagination_rejects_invalid_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?page=0');

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['page']);
}

public function test_catalog_search_rejects_excessively_long_value(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $search = str_repeat('a', 256);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?search=' . urlencode($search));

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['search']);
}

public function test_catalog_empty_search_returns_all_items(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?search=');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'meta' => [
                'total' => 3,
            ],
        ])
        ->assertJsonCount(3, 'data');
}
public function test_catalog_search_is_case_insensitive(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Premium Rice',
        'type' => 'product',
        'sku' => 'RICE-100',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?search=rice');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Premium Rice',
            'sku' => 'RICE-100',
        ]);
}

public function test_authenticated_user_can_search_catalog_item_by_sku(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Premium Rice',
        'type' => 'product',
        'sku' => 'RICE-100',
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Office Chair',
        'type' => 'product',
        'sku' => 'CHAIR-200',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?search=RICE-100');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Premium Rice',
            'sku' => 'RICE-100',
        ])
        ->assertJsonMissing([
            'name' => 'Office Chair',
            'sku' => 'CHAIR-200',
        ]);
}
public function test_authenticated_user_can_search_catalog_item_by_description(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Office Chair',
        'type' => 'product',
        'sku' => 'CHAIR-100',
        'description' => 'Ergonomic chair with lumbar support',
    ]);

    CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Office Desk',
        'type' => 'product',
        'sku' => 'DESK-100',
        'description' => 'Large wooden office desk',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/catalog-items?search=lumbar');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Office Chair',
            'sku' => 'CHAIR-100',
        ])
        ->assertJsonMissing([
            'name' => 'Office Desk',
            'sku' => 'DESK-100',
        ]);
}
}