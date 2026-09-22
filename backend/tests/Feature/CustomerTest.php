<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_be_created_for_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'John Doe',
        ]);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'tenant_id' => $tenant->id,
            'name' => 'John Doe',
        ]);
    }

    public function test_customer_belongs_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assertTrue(
            $customer->tenant->is($tenant)
        );
    }

    public function test_tenant_has_customers(): void
    {
        $tenant = Tenant::factory()->create();

        Customer::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assertCount(3, $tenant->customers);
    }

    public function test_customer_can_be_inactive(): void
    {
        $customer = Customer::factory()
            ->inactive()
            ->create();

        $this->assertEquals(
            'inactive',
            $customer->status
        );
    }

    public function test_customers_are_isolated_between_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Customer::factory()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Customer A',
        ]);

        Customer::factory()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Customer B',
        ]);

        $this->assertCount(
            1,
            $tenantA->customers
        );

        $this->assertEquals(
            'Customer A',
            $tenantA->customers->first()->name
        );

        $this->assertCount(
            1,
            $tenantB->customers
        );

        $this->assertEquals(
            'Customer B',
            $tenantB->customers->first()->name
        );
    }
}