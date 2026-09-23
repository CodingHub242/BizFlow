<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

public function test_authenticated_user_can_create_customer(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/customers', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '0244000000',
        ]);

    $response
        ->assertStatus(201)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('customers', [
        'tenant_id' => $tenant->id,
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '0244000000',
    ]);
}

public function test_created_customer_belongs_to_authenticated_users_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/customers', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '0244111111',
        ]);

    $response->assertStatus(201);

    $customer = Customer::query()
        ->where('email', 'jane@example.com')
        ->firstOrFail();

    $this->assertSame($tenant->id, $customer->tenant_id);
    $this->assertSame($user->tenant_id, $customer->tenant_id);
}

    public function test_unauthenticated_user_cannot_create_customer(): void
{
    $response = $this
        ->postJson('/api/customers', [
            'name' => 'Unauthenticated Customer',
            'email' => 'customer@example.com',
            'phone' => '0244222222',
        ]);

    $response->assertStatus(401);
}
public function test_authenticated_user_can_list_customers_for_their_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    Customer::factory()->count(2)->create([
        'tenant_id' => $tenantA->id,
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $response = $this
        ->actingAs($userA)
        ->getJson('/api/customers');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(2, 'data');
}

public function test_customer_list_does_not_include_other_tenants_customers(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $customerA = Customer::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $customerB = Customer::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $response = $this
        ->actingAs($userA)
        ->getJson('/api/customers');

    $response
        ->assertStatus(200)
        ->assertJsonFragment([
            'id' => $customerA->id,
        ])
        ->assertJsonMissing([
            'id' => $customerB->id,
        ]);
}

public function test_unauthenticated_user_cannot_list_customers(): void
{
    $response = $this->getJson('/api/customers');

    $response->assertStatus(401);
}

public function test_authenticated_user_can_view_customer_from_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Test Customer',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/customers/{$customer->id}");

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $customer->id,
                'name' => 'Test Customer',
            ],
        ]);
}

public function test_user_cannot_view_customer_from_another_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $customerB = Customer::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $response = $this
        ->actingAs($userA)
        ->getJson("/api/customers/{$customerB->id}");

    $response->assertStatus(404);
}

public function test_unauthenticated_user_cannot_view_customer(): void
{
    $customer = Customer::factory()->create();

    $response = $this->getJson("/api/customers/{$customer->id}");

    $response->assertStatus(401);
}

public function test_authenticated_user_can_update_customer_from_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Old Customer Name',
        'phone' => '0200000000',
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/customers/{$customer->id}", [
            'name' => 'Updated Customer Name',
            'phone' => '0240000000',
        ]);

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $customer->id,
                'name' => 'Updated Customer Name',
                'phone' => '0240000000',
            ],
        ]);

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'tenant_id' => $tenant->id,
        'name' => 'Updated Customer Name',
        'phone' => '0240000000',
    ]);
}

public function test_user_cannot_update_customer_from_another_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $customerB = Customer::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Original Customer',
    ]);

    $response = $this
        ->actingAs($userA)
        ->putJson("/api/customers/{$customerB->id}", [
            'name' => 'Hacked Customer',
        ]);

    $response->assertStatus(404);

    $this->assertDatabaseHas('customers', [
        'id' => $customerB->id,
        'tenant_id' => $tenantB->id,
        'name' => 'Original Customer',
    ]);
}

public function test_unauthenticated_user_cannot_update_customer(): void
{
    $customer = Customer::factory()->create([
        'name' => 'Original Customer',
    ]);

    $response = $this->putJson("/api/customers/{$customer->id}", [
        'name' => 'Updated Customer',
    ]);

    $response->assertStatus(401);

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'name' => 'Original Customer',
    ]);
}

public function test_customer_update_validates_name(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Original Customer',
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/customers/{$customer->id}", [
            'name' => '',
        ]);

    $response->assertStatus(422);

    $response->assertJsonValidationErrors([
        'name',
    ]);

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'name' => 'Original Customer',
    ]);
}
public function test_authenticated_user_can_delete_customer_from_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->deleteJson("/api/customers/{$customer->id}");

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertSoftDeleted('customers', [
        'id' => $customer->id,
        'tenant_id' => $tenant->id,
    ]);
}
public function test_user_cannot_delete_customer_from_another_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $customerB = Customer::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Customer',
    ]);

    $response = $this
        ->actingAs($userA)
        ->deleteJson("/api/customers/{$customerB->id}");

    $response->assertStatus(404);

    $this->assertDatabaseHas('customers', [
        'id' => $customerB->id,
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Customer',
        'deleted_at' => null,
    ]);
}

public function test_unauthenticated_user_cannot_delete_customer(): void
{
    $customer = Customer::factory()->create([
        'name' => 'Protected Customer',
    ]);

    $response = $this->deleteJson("/api/customers/{$customer->id}");

    $response->assertStatus(401);

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'name' => 'Protected Customer',
        'deleted_at' => null,
    ]);
}

public function test_authenticated_user_can_search_customers_by_name(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kwame Mensah',
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Ama Boateng',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers?search=Kwame');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Kwame Mensah',
        ]);
}

public function test_customer_search_does_not_include_other_tenants(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Kwame Mensah',
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Kwame Boateng',
    ]);

    $response = $this
        ->actingAs($userA)
        ->getJson('/api/customers?search=Kwame');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Kwame Mensah',
        ])
        ->assertJsonMissing([
            'name' => 'Kwame Boateng',
        ]);
}

public function test_authenticated_user_can_search_customers_by_email(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Ama Boateng',
        'email' => 'ama@example.com',
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kofi Mensah',
        'email' => 'kofi@example.com',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers?search=ama@example.com');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'email' => 'ama@example.com',
        ]);
}

public function test_authenticated_user_can_search_customers_by_phone(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Ama Boateng',
        'phone' => '0241234567',
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kofi Mensah',
        'phone' => '0209876543',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers?search=0241234567');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'phone' => '0241234567',
        ]);
}

public function test_authenticated_user_can_paginate_customers(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->count(20)->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers?per_page=10');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
    ]);

    $response->assertJsonCount(10, 'data');
}

public function test_customer_pagination_does_not_include_other_tenants(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    Customer::factory()->count(10)->create([
        'tenant_id' => $tenantA->id,
    ]);

    Customer::factory()->count(10)->create([
        'tenant_id' => $tenantB->id,
    ]);

    $response = $this
        ->actingAs($userA)
        ->getJson('/api/customers?per_page=15');

    $response
        ->assertStatus(200)
        ->assertJsonCount(10, 'data');

    $response->assertJson([
        'meta' => [
            'total' => 10,
        ],
    ]);
}

public function test_customer_pagination_limits_maximum_per_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->count(105)->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers?per_page=1000');

    $response
        ->assertStatus(200)
        ->assertJsonCount(100, 'data')
        ->assertJson([
            'meta' => [
                'per_page' => 100,
                'total' => 105,
            ],
        ]);
}

public function test_customer_pagination_enforces_minimum_per_page(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->count(5)->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers?per_page=0');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJson([
            'meta' => [
                'per_page' => 1,
                'total' => 5,
            ],
        ]);
}

public function test_customer_search_works_with_pagination(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->count(5)->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kwame Customer',
    ]);

    Customer::factory()->count(5)->create([
        'tenant_id' => $tenant->id,
        'name' => 'Ama Customer',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers?search=Kwame&per_page=2');

    $response
        ->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJson([
            'meta' => [
                'per_page' => 2,
                'total' => 5,
                'last_page' => 3,
            ],
        ]);

    $response->assertJsonMissing([
        'name' => 'Ama Customer',
    ]);
}

public function test_customer_search_returns_empty_data_when_no_customer_matches(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kwame Mensah',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers?search=NonExistentCustomer');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [],
        ])
        ->assertJsonPath('meta.total', 0);
}
public function test_customer_view_returns_expected_resource_structure(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
    'tenant_id' => $tenant->id,
    'name' => 'Kwame Mensah',
    'email' => 'kwame@example.com',
    'phone' => '0241234567',
    'address' => 'Accra',
    'notes' => 'Important customer',
]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/customers/{$customer->id}");

    $response
    ->assertStatus(200)
    ->assertJsonStructure([
        'success',
        'data' => [
            'id',
            'name',
            'email',
            'phone',
            'company_name',
            'address',
            'status',
            'notes',
            'created_at',
            'updated_at',
        ],
    ]);
}

public function test_customer_create_returns_expected_resource_structure(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/customers', [
            'name' => 'Kwame Mensah',
            'email' => 'kwame@example.com',
            'phone' => '0241234567',
            'company_name' => 'Kwame Trading',
            'address' => 'Accra',
            'notes' => 'Important customer',
        ]);

    $response
        ->assertStatus(201)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'id',
                'name',
                'email',
                'phone',
                'company_name',
                'address',
                'status',
                'notes',
                'created_at',
                'updated_at',
            ],
        ]);
        
}

public function test_customer_update_returns_expected_resource_structure(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Old Customer',
        'email' => 'old@example.com',
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/customers/{$customer->id}", [
            'name' => 'Updated Customer',
            'email' => 'updated@example.com',
        ]);

    $response
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'id',
                'name',
                'email',
                'phone',
                'company_name',
                'address',
                'status',
                'notes',
                'created_at',
                'updated_at',
            ],
        ]);
}

public function test_customer_resource_does_not_expose_tenant_id(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kwame Mensah',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/customers/{$customer->id}");

    $response
        ->assertStatus(200)
        ->assertJsonMissing([
            'tenant_id' => $tenant->id,
        ]);
}

public function test_customer_list_uses_customer_resource_structure(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kwame Mensah',
        'email' => 'kwame@example.com',
        'phone' => '0241234567',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers');

    $response
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'email',
                    'phone',
                    'company_name',
                    'address',
                    'status',
                    'notes',
                    'created_at',
                    'updated_at',
                ],
            ],
            'meta' => [
                'current_page',
                'per_page',
                'total',
                'last_page',
            ],
        ])
        ->assertJsonMissing([
            'tenant_id' => $tenant->id,
        ]);
}

public function test_authenticated_user_can_update_customer_status(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kwame Mensah',
        'status' => 'active',
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/customers/{$customer->id}", [
            'status' => 'inactive',
        ]);

    $response
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'inactive');

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'tenant_id' => $tenant->id,
        'name' => 'Kwame Mensah',
        'status' => 'inactive',
    ]);
}

public function test_customer_status_must_be_active_or_inactive(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'active',
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/customers/{$customer->id}", [
            'status' => 'suspended',
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'status',
        ]);

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'tenant_id' => $tenant->id,
        'status' => 'active',
    ]);
}

public function test_authenticated_user_can_filter_customers_by_status(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Active Customer',
        'status' => 'active',
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Inactive Customer',
        'status' => 'inactive',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers?status=active');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Active Customer',
        ])
        ->assertJsonMissing([
            'name' => 'Inactive Customer',
        ]);
}

public function test_customer_status_filter_does_not_include_other_tenants(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Tenant A Active',
        'status' => 'active',
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Active',
        'status' => 'active',
    ]);

    $response = $this
        ->actingAs($userA)
        ->getJson('/api/customers?status=active');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Tenant A Active',
        ])
        ->assertJsonMissing([
            'name' => 'Tenant B Active',
        ]);
}

public function test_customer_status_filter_rejects_invalid_status(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'active',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers?status=suspended');

    $response->assertStatus(422);

    $response->assertJsonValidationErrors([
        'status',
    ]);
}

public function test_customer_search_and_status_filters_work_together(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kwame Active',
        'status' => 'active',
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kwame Inactive',
        'status' => 'inactive',
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Ama Active',
        'status' => 'active',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers?search=Kwame&status=active');

    $response
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment([
            'name' => 'Kwame Active',
            'status' => 'active',
        ])
        ->assertJsonMissing([
            'name' => 'Kwame Inactive',
        ])
        ->assertJsonMissing([
            'name' => 'Ama Active',
        ]);
}

public function test_customer_search_status_and_pagination_work_together(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->count(5)->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kwame Active',
        'status' => 'active',
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kwame Inactive',
        'status' => 'inactive',
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Ama Active',
        'status' => 'active',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/customers?search=Kwame&status=active&per_page=2');

    $response
        ->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJson([
            'meta' => [
                'per_page' => 2,
                'total' => 5,
                'last_page' => 3,
            ],
        ]);

    $response->assertJsonMissing([
        'name' => 'Kwame Inactive',
    ]);

    $response->assertJsonMissing([
        'name' => 'Ama Active',
    ]);
}

public function test_authenticated_user_can_restore_deleted_customer_from_their_tenant(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Restorable Customer',
    ]);

    $customer->delete();

    $response = $this
        ->actingAs($user)
        ->postJson("/api/customers/{$customer->id}/restore");

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'tenant_id' => $tenant->id,
        'name' => 'Restorable Customer',
        'deleted_at' => null,
    ]);
}

public function test_user_cannot_restore_deleted_customer_from_another_tenant(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Other Tenant Customer',
    ]);

    $customer->delete();

    $response = $this
        ->actingAs($user)
        ->postJson("/api/customers/{$customer->id}/restore");

    $response->assertStatus(404);

    $this->assertSoftDeleted('customers', [
        'id' => $customer->id,
        'tenant_id' => $tenantB->id,
    ]);
}

public function test_user_cannot_restore_customer_that_is_not_deleted(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Active Customer',
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson("/api/customers/{$customer->id}/restore");

    $response
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Customer is not deleted.',
        ]);

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'tenant_id' => $tenant->id,
        'name' => 'Active Customer',
        'deleted_at' => null,
    ]);
}

public function test_unauthenticated_user_cannot_restore_deleted_customer(): void
{
    $tenant = Tenant::factory()->create();

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Deleted Customer',
    ]);

    $customer->delete();

    $response = $this->postJson("/api/customers/{$customer->id}/restore");

    $response->assertStatus(401);

    $this->assertSoftDeleted('customers', [
        'id' => $customer->id,
        'tenant_id' => $tenant->id,
    ]);
}
}