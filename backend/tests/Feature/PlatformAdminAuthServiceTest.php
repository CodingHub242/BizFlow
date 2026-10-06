<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Services\Platform\PlatformAdminAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlatformAdminAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_active_platform_admin_can_login(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $service = app(PlatformAdminAuthService::class);

        $result = $service->attempt(
            'admin@bizflow.test',
            'SecurePassword123!'
        );

        $this->assertTrue($result);
        $this->assertAuthenticatedAs($admin, 'platform');
    }

    public function test_inactive_platform_admin_cannot_login(): void
    {
        PlatformAdmin::create([
            'name' => 'Inactive Administrator',
            'email' => 'inactive@bizflow.test',
            'password' => 'SecurePassword123!',
            'is_active' => false,
        ]);

        $service = app(PlatformAdminAuthService::class);

        $result = $service->attempt(
            'inactive@bizflow.test',
            'SecurePassword123!'
        );

        $this->assertFalse($result);
        $this->assertGuest('platform');
    }

    public function test_wrong_password_cannot_login(): void
    {
        PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $service = app(PlatformAdminAuthService::class);

        $result = $service->attempt(
            'admin@bizflow.test',
            'WrongPassword123!'
        );

        $this->assertFalse($result);
        $this->assertGuest('platform');
    }

    public function test_unknown_email_cannot_login(): void
    {
        $service = app(PlatformAdminAuthService::class);

        $result = $service->attempt(
            'unknown@bizflow.test',
            'SecurePassword123!'
        );

        $this->assertFalse($result);
        $this->assertGuest('platform');
    }

    public function test_admin_can_logout(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $service = app(PlatformAdminAuthService::class);

        $service->attempt(
            'admin@bizflow.test',
            'SecurePassword123!'
        );

        $this->assertAuthenticatedAs($admin, 'platform');

        $service->logout();

        $this->assertGuest('platform');
    }

    public function test_three_failed_attempts_trigger_lockout(): void
    {
        PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $service = app(PlatformAdminAuthService::class);

        $this->assertFalse(
            $service->attempt(
                'admin@bizflow.test',
                'WrongPassword123!'
            )
        );

        $this->assertFalse(
            $service->attempt(
                'admin@bizflow.test',
                'WrongPassword123!'
            )
        );

        $this->assertFalse(
            $service->attempt(
                'admin@bizflow.test',
                'WrongPassword123!'
            )
        );

        // Correct credentials should still be blocked.
        $this->assertFalse(
            $service->attempt(
                'admin@bizflow.test',
                'SecurePassword123!'
            )
        );

        $this->assertGuest('platform');
    }

    public function test_lockout_expires_after_fifteen_minutes(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $service = app(PlatformAdminAuthService::class);

        for ($i = 0; $i < 3; $i++) {
            $service->attempt(
                'admin@bizflow.test',
                'WrongPassword123!'
            );
        }

        $this->assertFalse(
            $service->attempt(
                'admin@bizflow.test',
                'SecurePassword123!'
            )
        );

        $this->travel(16)->minutes();

        $this->assertTrue(
            $service->attempt(
                'admin@bizflow.test',
                'SecurePassword123!'
            )
        );

        $this->assertAuthenticatedAs($admin, 'platform');
    }

    public function test_successful_login_clears_failed_attempts(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $service = app(PlatformAdminAuthService::class);

        $service->attempt(
            'admin@bizflow.test',
            'WrongPassword123!'
        );

        $service->attempt(
            'admin@bizflow.test',
            'SecurePassword123!'
        );

        Auth::guard('platform')->logout();

        // We should have a fresh failure allowance.
        $this->assertFalse(
            $service->attempt(
                'admin@bizflow.test',
                'WrongPassword123!'
            )
        );

        $this->assertTrue(
            $service->attempt(
                'admin@bizflow.test',
                'SecurePassword123!'
            )
        );
    }

    public function test_nonexistent_email_and_wrong_password_both_fail_without_authentication(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $service = app(PlatformAdminAuthService::class);

        $this->assertFalse(
            $service->attempt(
                'does-not-exist@bizflow.test',
                'Anything123!'
            )
        );

        $this->assertFalse(
            $service->attempt(
                'admin@bizflow.test',
                'WrongPassword123!'
            )
        );

        $this->assertGuest('platform');

        // Keep the variable intentionally referenced so the fixture
        // clearly documents that a real admin exists.
        $this->assertDatabaseHas('platform_admins', [
            'id' => $admin->id,
        ]);
    }
}