<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_be_created(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $this->assertDatabaseHas('platform_admins', [
            'id' => $admin->id,
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'is_active' => true,
        ]);
    }

    public function test_platform_admin_password_is_hashed(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $this->assertNotSame(
            'SecurePassword123!',
            $admin->password
        );

        $this->assertTrue(
            Hash::check('SecurePassword123!', $admin->password)
        );
    }

    public function test_platform_admin_email_is_unique(): void
    {
        PlatformAdmin::create([
            'name' => 'First Admin',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PlatformAdmin::create([
            'name' => 'Second Admin',
            'email' => 'admin@bizflow.test',
            'password' => 'AnotherPassword123!',
        ]);
    }

    public function test_platform_admin_can_be_deactivated(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $admin->update([
            'is_active' => false,
        ]);

        $this->assertFalse($admin->fresh()->is_active);
    }

    public function test_platform_admin_can_authenticate_using_platform_guard(): void
{
    $admin = PlatformAdmin::create([
        'name' => 'Platform Administrator',
        'email' => 'admin@bizflow.test',
        'password' => 'SecurePassword123!',
    ]);

    $authenticated = Auth::guard('platform')->attempt([
        'email' => 'admin@bizflow.test',
        'password' => 'SecurePassword123!',
    ]);

    $this->assertTrue($authenticated);
    $this->assertAuthenticatedAs($admin, 'platform');
}

}