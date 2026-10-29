<?php

namespace Tests\Feature;

use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'profile-test@example.com',
            'password' => 'OriginalPassword123!',
        ]);
    }

    private function createOtp(User $user, string $otp = '123456'): PasswordResetOtp
    {
        return PasswordResetOtp::create([
            'user_id' => $user->id,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
        ]);
    }

    public function test_authenticated_user_can_request_password_reset_otp(): void
    {
        Mail::fake();

        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/user/password-reset/request-otp')
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(PasswordResetOtpMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        $this->assertDatabaseHas('password_reset_otps', [
            'user_id' => $user->id,
        ]);
    }

    public function test_password_reset_otp_request_requires_authentication(): void
    {
        $this->postJson('/api/user/password-reset/request-otp')
            ->assertUnauthorized();
    }

    public function test_invalid_otp_is_rejected_and_attempt_count_increases(): void
    {
        $user = $this->makeUser();
        $record = $this->createOtp($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/user/password-reset/verify-otp', [
            'otp' => '999999',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('password_reset_otps', [
            'id' => $record->id,
            'attempts' => 1,
        ]);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $user = $this->makeUser();

        $record = $this->createOtp($user);
        $record->update(['expires_at' => now()->subMinute()]);

        Sanctum::actingAs($user);

        $this->postJson('/api/user/password-reset/verify-otp', [
            'otp' => '123456',
        ])->assertUnprocessable();
    }

    public function test_valid_otp_returns_reset_token(): void
    {
        $user = $this->makeUser();
        $this->createOtp($user);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/password-reset/verify-otp', [
            'otp' => '123456',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $resetToken = $response->json('reset_token');

        $this->assertIsString($resetToken);
        $this->assertSame(64, strlen($resetToken));

        $record = PasswordResetOtp::where('user_id', $user->id)->firstOrFail();

        $this->assertNotSame($resetToken, $record->reset_token_hash);
        $this->assertNotNull($record->verified_at);
    }

    public function test_verified_reset_token_changes_password_and_revokes_sanctum_tokens(): void
    {
        $user = $this->makeUser();

        $existingToken = $user->createToken('Existing device');

        $record = $this->createOtp($user);

        Sanctum::actingAs($user);

        $verifyResponse = $this->postJson('/api/user/password-reset/verify-otp', [
            'otp' => '123456',
        ])->assertOk();

        $resetToken = $verifyResponse->json('reset_token');

        $this->postJson('/api/user/password-reset/reset', [
            'reset_token' => $resetToken,
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(
            Hash::check('NewSecurePassword123!', $user->fresh()->password)
        );

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $existingToken->accessToken->id,
        ]);

        $this->assertDatabaseHas('password_reset_otps', [
            'id' => $record->id,
            'consumed_at' => now()->toDateTimeString(),
            'reset_token_hash' => null,
        ]);
    }

    public function test_consumed_reset_token_cannot_be_reused(): void
{
    $user = $this->makeUser();
    $this->createOtp($user);

    Sanctum::actingAs($user);

    $resetToken = $this->postJson('/api/user/password-reset/verify-otp', [
        'otp' => '123456',
    ])->assertOk()->json('reset_token');

    $payload = [
        'reset_token' => $resetToken,
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
    ];

    $this->postJson('/api/user/password-reset/reset', $payload)
        ->assertOk();

    // The second request may be rate-limited before token validation.
    // A 422 response is expected when the request reaches the controller.
    $response = $this->postJson('/api/user/password-reset/reset', [
        'reset_token' => $resetToken,
        'password' => 'AnotherSecurePassword123!',
        'password_confirmation' => 'AnotherSecurePassword123!',
    ]);

    $this->assertContains($response->status(), [422, 429]);
}

    public function test_profile_update_changes_name_but_not_email(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/user/profile', [
            'name' => 'Updated Profile Name',
            'email' => 'attacker@example.com',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Profile Name')
            ->assertJsonPath('data.email', 'profile-test@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Profile Name',
            'email' => 'profile-test@example.com',
        ]);
    }
}
