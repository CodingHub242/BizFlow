<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Tenant\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessOnboardingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_can_register_through_api(): void
    {
        $response = $this->postJson('/api/onboarding/register', [
            'business_name' => 'API Test Business',
            'business_email' => 'business@example.com',
            'business_phone' => '0240000030',
            'business_type' => 'Retail',
            'owner_name' => 'API Business Owner',
            'owner_email' => 'owner@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath(
                'message',
                'Business registration submitted successfully. Your account is awaiting approval.'
            )
            ->assertJsonPath('data.tenant.status', 'pending')
            ->assertJsonPath('data.tenant.name', 'API Test Business')
            ->assertJsonPath('data.owner.name', 'API Business Owner')
            ->assertJsonPath('data.owner.email', 'owner@example.com')
            ->assertJsonMissingPath('data.owner.password');

        $this->assertDatabaseHas('tenants', [
            'name' => 'API Test Business',
            'email' => 'business@example.com',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('users', [
            'name' => 'API Business Owner',
            'email' => 'owner@example.com',
        ]);
    }

    public function test_registered_owner_is_assigned_owner_role(): void
    {
        $response = $this->postJson('/api/onboarding/register', [
            'business_name' => 'Role Test Business',
            'business_email' => 'role-business@example.com',
            'business_phone' => '0240000031',
            'business_type' => 'Construction',
            'owner_name' => 'Role Test Owner',
            'owner_email' => 'role-owner@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ]);

        $response->assertStatus(201);

        $tenantId = $response->json('data.tenant.id');
        $ownerId = $response->json('data.owner.id');

        $role = Role::where('name', 'Owner')
            ->where('guard_name', 'web')
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $role->id,
            'model_id' => $ownerId,
            'model_type' => User::class,
            'tenant_id' => $tenantId,
        ]);
    }

    public function test_business_registration_requires_valid_fields(): void
    {
        $response = $this->postJson('/api/onboarding/register', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'business_name',
                'business_email',
                'business_phone',
                'business_type',
                'owner_name',
                'owner_email',
                'password',
            ]);
    }

    public function test_business_registration_rejects_duplicate_owner_email(): void
    {
        $this->postJson('/api/onboarding/register', [
            'business_name' => 'First Business',
            'business_email' => 'first-business@example.com',
            'business_phone' => '0240000032',
            'business_type' => 'Retail',
            'owner_name' => 'First Owner',
            'owner_email' => 'duplicate@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ])->assertStatus(201);

        $response = $this->postJson('/api/onboarding/register', [
            'business_name' => 'Second Business',
            'business_email' => 'second-business@example.com',
            'business_phone' => '0240000033',
            'business_type' => 'Retail',
            'owner_name' => 'Second Owner',
            'owner_email' => 'duplicate@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_email']);
    }

    public function test_registration_cannot_set_tenant_status_to_approved(): void
    {
        $response = $this->postJson('/api/onboarding/register', [
            'business_name' => 'Security Test Business',
            'business_email' => 'security-business@example.com',
            'business_phone' => '0240000034',
            'business_type' => 'Retail',
            'owner_name' => 'Security Owner',
            'owner_email' => 'security-owner@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
            'status' => 'approved',
        ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.tenant.status', 'pending');
    }

    public function test_password_is_hashed(): void
    {
        $response = $this->postJson('/api/onboarding/register', [
            'business_name' => 'Hash Test Business',
            'business_email' => 'hash-business@example.com',
            'business_phone' => '0240000035',
            'business_type' => 'Retail',
            'owner_name' => 'Hash Test Owner',
            'owner_email' => 'hash-owner@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ]);

        $response->assertStatus(201);

        $user = User::where(
            'email',
            'hash-owner@example.com'
        )->firstOrFail();

        $this->assertTrue(
            Hash::check('StrongPassword123!', $user->password)
        );

        $this->assertNotSame(
            'StrongPassword123!',
            $user->password
        );
    }
}