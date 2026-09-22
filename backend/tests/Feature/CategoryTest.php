<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_create_a_category(): void
    {
        $tenant = Tenant::create([
            'name' => 'Interior Design Business',
            'slug' => 'interior-design-business',
        ]);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Design Services',
            'description' => 'Interior design related services',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'tenant_id' => $tenant->id,
            'name' => 'Design Services',
        ]);

        $this->assertTrue($category->tenant->is($tenant));
    }

    public function test_categories_are_isolated_between_tenants(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Business A',
            'slug' => 'business-a',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Business B',
            'slug' => 'business-b',
        ]);

        $categoryA = Category::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Furniture',
        ]);

        $categoryB = Category::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Furniture',
        ]);

        $tenantACategories = $tenantA->categories()->get();

        $this->assertTrue($tenantACategories->contains($categoryA));
        $this->assertFalse($tenantACategories->contains($categoryB));
    }

    public function test_category_names_must_be_unique_within_a_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Business A',
            'slug' => 'business-a',
        ]);

        Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Furniture',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Furniture',
        ]);
    }

    public function test_different_tenants_can_have_categories_with_the_same_name(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Business A',
            'slug' => 'business-a',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Business B',
            'slug' => 'business-b',
        ]);

        $categoryA = Category::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Furniture',
        ]);

        $categoryB = Category::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Furniture',
        ]);

        $this->assertNotSame($categoryA->id, $categoryB->id);

        $this->assertSame(
            'Furniture',
            $categoryA->name
        );

        $this->assertSame(
            'Furniture',
            $categoryB->name
        );
    }
}