<?php

namespace Tests\Feature;

use App\CatalogItemType;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_create_a_physical_product(): void
    {
        $tenant = Tenant::create([
            'name' => 'Retail Business',
            'slug' => 'retail-business',
        ]);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Furniture',
        ]);

        $product = CatalogItem::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'type' => CatalogItemType::PRODUCT,
            'name' => 'Office Chair',
            'sku' => 'CHAIR-001',
            'description' => 'Ergonomic office chair',
            'unit' => 'piece',
            'cost_price' => 850.00,
            'selling_price' => 1200.00,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('catalog_items', [
            'id' => $product->id,
            'tenant_id' => $tenant->id,
            'name' => 'Office Chair',
            'type' => 'product',
            'track_inventory' => true,
        ]);

        $this->assertTrue($product->category->is($category));
        $this->assertTrue($product->tenant->is($tenant));
    }

    public function test_tenant_can_create_a_service(): void
    {
        $tenant = Tenant::create([
            'name' => 'Interior Design Business',
            'slug' => 'interior-design-business',
        ]);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Design Services',
        ]);

        $service = CatalogItem::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'type' => CatalogItemType::SERVICE,
            'name' => 'Interior Design Consultation',
            'sku' => 'SVC-001',
            'description' => 'Professional interior design consultation',
            'unit' => 'session',
            'cost_price' => 150.00,
            'selling_price' => 500.00,
            'track_inventory' => false,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('catalog_items', [
            'id' => $service->id,
            'tenant_id' => $tenant->id,
            'name' => 'Interior Design Consultation',
            'type' => 'service',
            'track_inventory' => false,
        ]);

        $this->assertTrue($service->category->is($category));
        $this->assertTrue($service->tenant->is($tenant));
    }

    public function test_catalog_items_are_isolated_between_tenants(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Business A',
            'slug' => 'business-a',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Business B',
            'slug' => 'business-b',
        ]);

        $productA = CatalogItem::create([
            'tenant_id' => $tenantA->id,
            'type' => CatalogItemType::PRODUCT,
            'name' => 'Business A Product',
            'selling_price' => 100.00,
            'track_inventory' => true,
        ]);

        $productB = CatalogItem::create([
            'tenant_id' => $tenantB->id,
            'type' => CatalogItemType::PRODUCT,
            'name' => 'Business B Product',
            'selling_price' => 200.00,
            'track_inventory' => true,
        ]);

        $tenantAItems = $tenantA->catalogItems()->get();

        $this->assertTrue($tenantAItems->contains($productA));
        $this->assertFalse($tenantAItems->contains($productB));
    }

    public function test_sku_must_be_unique_within_a_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Business A',
            'slug' => 'business-a',
        ]);

        CatalogItem::create([
            'tenant_id' => $tenant->id,
            'type' => CatalogItemType::PRODUCT,
            'name' => 'Office Chair',
            'sku' => 'CHAIR-001',
            'selling_price' => 1200.00,
            'track_inventory' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        CatalogItem::create([
            'tenant_id' => $tenant->id,
            'type' => CatalogItemType::PRODUCT,
            'name' => 'Executive Chair',
            'sku' => 'CHAIR-001',
            'selling_price' => 1800.00,
            'track_inventory' => true,
        ]);
    }

    public function test_different_tenants_can_use_the_same_sku(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Business A',
            'slug' => 'business-a',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Business B',
            'slug' => 'business-b',
        ]);

        $productA = CatalogItem::create([
            'tenant_id' => $tenantA->id,
            'type' => CatalogItemType::PRODUCT,
            'name' => 'Office Chair',
            'sku' => 'CHAIR-001',
            'selling_price' => 1200.00,
            'track_inventory' => true,
        ]);

        $productB = CatalogItem::create([
            'tenant_id' => $tenantB->id,
            'type' => CatalogItemType::PRODUCT,
            'name' => 'Office Chair',
            'sku' => 'CHAIR-001',
            'selling_price' => 1400.00,
            'track_inventory' => true,
        ]);

        $this->assertNotSame($productA->id, $productB->id);

        $this->assertSame($tenantA->id, $productA->tenant_id);
        $this->assertSame($tenantB->id, $productB->tenant_id);
    }
}