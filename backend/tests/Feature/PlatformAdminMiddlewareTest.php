<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use App\Models\Tenant;
use App\Models\User;
use App\TenantStatus;
use Tests\TestCase;

class PlatformAdminMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_platform_login(): void
    {
        $response = $this->get('/platform/dashboard');

        $response->assertRedirect(route('platform.login'));
    }

    public function test_authenticated_platform_admin_can_access_platform_area(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        Auth::guard('platform')->login($admin);

        $response = $this->get('/platform/dashboard');

        $response->assertSuccessful();
    }

   public function test_tenant_user_cannot_access_platform_area(): void
{
    $tenant = Tenant::create([
        'name' => 'Test Business',
        'slug' => 'test-business',
        'email' => 'business@bizflow.test',
        'phone' => '0200000000',
        'business_type' => 'Retail',
        'status' => TenantStatus::APPROVED,
    ]);

    $user = User::create([
        'tenant_id' => $tenant->id,
        'name' => 'Tenant User',
        'email' => 'user@bizflow.test',
        'password' => 'SecurePassword123!',
    ]);

    Auth::guard('web')->login($user);

    $this->assertAuthenticatedAs($user, 'web');
    $this->assertGuest('platform');

    $response = $this->get('/platform/dashboard');

    $response->assertRedirect(route('platform.login'));
}

    public function test_platform_guard_is_separate_from_web_guard(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        Auth::guard('platform')->login($admin);

        $this->assertAuthenticatedAs($admin, 'platform');
        $this->assertGuest('web');
    }
}