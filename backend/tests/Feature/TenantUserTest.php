<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_belongs_to_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Business',
            'slug' => 'test-business',
            'email' => 'business@example.com',
            'phone' => '0240000000',
            'business_type' => 'Retail',
            'status' => 'active',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password',
        ]);

        $this->assertTrue($user->tenant->is($tenant));
    }

    public function test_tenant_has_many_users(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Business',
            'slug' => 'test-business',
            'email' => 'business@example.com',
            'phone' => '0240000000',
            'business_type' => 'Retail',
            'status' => 'active',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password',
        ]);

        $this->assertTrue($tenant->users->contains($user));
    }
}