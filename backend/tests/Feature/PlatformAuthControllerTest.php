<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PlatformAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('platform-login|127.0.0.1');
    }

    public function test_platform_login_requires_email_and_password(): void
    {
        $response = $this->post('/platform/login', []);

        $response->assertSessionHasErrors([
            'email',
            'password',
        ]);
    }

    public function test_platform_admin_can_login_with_valid_credentials(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $response = $this->post('/platform/login', [
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertRedirect(route('platform.dashboard'));

        $this->assertAuthenticatedAs($admin, 'platform');
    }

    public function test_wrong_password_returns_generic_error(): void
    {
        PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $response = $this->post('/platform/login', [
            'email' => 'admin@bizflow.test',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertSessionHasErrors([
            'email' => 'The provided credentials are invalid.',
        ]);

        $response->assertSessionDoesntHaveErrors([
            'password',
        ]);

        $this->assertGuest('platform');
    }

    public function test_unknown_email_returns_same_generic_error(): void
    {
        $response = $this->post('/platform/login', [
            'email' => 'unknown@bizflow.test',
            'password' => 'Anything123!',
        ]);

        $response->assertSessionHasErrors([
            'email' => 'The provided credentials are invalid.',
        ]);

        $this->assertGuest('platform');
    }

    public function test_inactive_admin_returns_same_generic_error(): void
    {
        PlatformAdmin::create([
            'name' => 'Inactive Administrator',
            'email' => 'inactive@bizflow.test',
            'password' => 'SecurePassword123!',
            'is_active' => false,
        ]);

        $response = $this->post('/platform/login', [
            'email' => 'inactive@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertSessionHasErrors([
            'email' => 'The provided credentials are invalid.',
        ]);

        $this->assertGuest('platform');
    }

    public function test_three_failed_login_attempts_trigger_lockout(): void
    {
        PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        for ($i = 0; $i < 2; $i++) {
            $response = $this->post('/platform/login', [
                'email' => 'admin@bizflow.test',
                'password' => 'WrongPassword123!',
            ]);

            $response->assertSessionHasErrors([
                'email' => 'The provided credentials are invalid.',
            ]);
        }

        // Third failed attempt triggers the lockout.
        $response = $this->post('/platform/login', [
            'email' => 'admin@bizflow.test',
            'password' => 'WrongPassword123!',
        ]);

      $response->assertSessionHasErrors([
            'email' => 'Sign-in is temporarily unavailable. Please try again later.',
        ]);

        // Correct password must still be rejected while locked.
        $response = $this->post('/platform/login', [
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

       $response->assertSessionHasErrors([
    'email' => 'Sign-in is temporarily unavailable. Please try again later.',
]);

        $this->assertGuest('platform');
    }

    public function test_login_regenerates_session_after_successful_authentication(): void
    {
        PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $this->withSession([
            'test_marker' => 'keep-me',
        ]);

        $response = $this->post('/platform/login', [
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertRedirect(route('platform.dashboard'));

        $this->assertAuthenticated('platform');
        $this->assertEquals('keep-me', session('test_marker'));
    }

    public function test_platform_logout_ends_platform_session(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $this->actingAs($admin, 'platform');

        $response = $this->post('/platform/logout');

        $response->assertRedirect(route('platform.login'));

        $this->assertGuest('platform');
    }
 public function test_platform_login_is_rate_limited_by_ip(): void
{
    for ($i = 1; $i <= 5; $i++) {
        $response = $this->post('/platform/login', [
            'email' => "unknown{$i}@bizflow.test",
            'password' => 'WrongPassword123!',
        ]);

        $response->assertSessionHasErrors([
            'email' => 'The provided credentials are invalid.',
        ]);
    }

    $response = $this->post('/platform/login', [
        'email' => 'another-unknown@bizflow.test',
        'password' => 'WrongPassword123!',
    ]);

    $response->assertStatus(429);
}
public function test_lockout_returns_temporary_unavailable_message(): void
{
    PlatformAdmin::create([
        'name' => 'Platform Administrator',
        'email' => 'admin@bizflow.test',
        'password' => 'SecurePassword123!',
    ]);

    for ($i = 0; $i < 3; $i++) {
        $this->post('/platform/login', [
            'email' => 'admin@bizflow.test',
            'password' => 'WrongPassword123!',
        ]);
    }

    $response = $this->post('/platform/login', [
        'email' => 'admin@bizflow.test',
        'password' => 'SecurePassword123!',
    ]);

    $response->assertSessionHasErrors([
        'email' => 'Sign-in is temporarily unavailable. Please try again later.',
    ]);

    $response->assertSessionHas('login_locked');

    $this->assertGuest('platform');
}
}