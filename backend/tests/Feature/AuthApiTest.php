<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Login successful.',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'token',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'tenant_id',
                ],
            ]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
{
    $tenant = Tenant::factory()->create();

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'owner@example.com',
        'password' => 'wrong-password',
    ]);

    $response
        ->assertStatus(401)
        ->assertJson([
            'success' => false,
            'message' => 'Invalid credentials.',
        ]);
}
public function test_user_can_logout_and_revoke_current_token(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $token = $user->createToken('BizFlow Web')->plainTextToken;

    $response = $this
        ->withToken($token)
        ->postJson('/api/logout');

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Logout successful.',
        ]);

    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_id' => $user->id,
    ]);

    Auth::forgetGuards();

    $this
        ->withToken($token)
        ->getJson('/api/user')
        ->assertUnauthorized();
}
public function test_api_requests_are_rate_limited(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Sanctum::actingAs($user);

    for ($i = 1; $i <= 61; $i++) {
        $response = $this
            ->withHeader('Accept', 'application/json')
            ->getJson('/api/user');

        if ($i <= 60) {
            $response->assertOk();
        } else {
            $response->assertStatus(429);
        }
    }
}
}