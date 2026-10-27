<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantSettingsTest extends TestCase
{
    use RefreshDatabase;

   private function createUserWithPermissions(
    Tenant $tenant,
    array $permissions
): User {
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    // Ensure all permissions referenced by the controller exist.
    foreach ([
        'settings.view',
        'settings.update',
        'settings.update_logo',
    ] as $permissionName) {
        Permission::findOrCreate($permissionName, 'web');
    }

    $role = Role::query()->firstOrCreate([
        'tenant_id' => $tenant->id,
        'name' => 'Settings Test Role',
        'guard_name' => 'web',
    ]);

    // Give the role only the permissions requested by this test.
    $role->syncPermissions($permissions);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $user->assignRole($role);

    return $user;
}

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)
            ->setPermissionsTeamId(null);

        parent::tearDown();
    }

    public function test_user_with_view_permission_can_view_business_profile(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Business A',
            'slug' => 'business-a',
            'email' => 'business@example.com',
            'phone' => '0200000000',
            'business_type' => 'Retail',
        ]);

        $user = $this->createUserWithPermissions(
            $tenant,
            ['settings.view'],
        );

        $this->actingAs($user)
            ->getJson('/api/settings/business-profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Business A')
            ->assertJsonPath('data.slug', 'business-a')
            ->assertJsonPath('data.business_type', 'Retail');
    }

    public function test_user_without_view_permission_cannot_view_business_profile(): void
    {
        $tenant = Tenant::factory()->create();

        $user = $this->createUserWithPermissions(
            $tenant,
            [],
        );

        $this->actingAs($user)
            ->getJson('/api/settings/business-profile')
            ->assertForbidden();
    }

    public function test_user_with_update_permission_can_update_business_profile(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Old Business Name',
        ]);

        $user = $this->createUserWithPermissions(
            $tenant,
            ['settings.view', 'settings.update'],
        );

        $this->actingAs($user)
            ->putJson('/api/settings/business-profile', [
                'name' => 'New Business Name',
                'email' => 'new@example.com',
                'phone' => '0240000000',
                'business_type' => 'Services',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'New Business Name');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'New Business Name',
            'email' => 'new@example.com',
            'phone' => '0240000000',
            'business_type' => 'Services',
        ]);
    }

    public function test_user_without_update_permission_cannot_update_business_profile(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Original Business',
        ]);

        $user = $this->createUserWithPermissions(
            $tenant,
            ['settings.view'],
        );

        $this->actingAs($user)
            ->putJson('/api/settings/business-profile', [
                'name' => 'Unauthorized Change',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Original Business',
        ]);
    }

    public function test_unauthenticated_user_cannot_view_business_profile(): void
    {
        $this->getJson('/api/settings/business-profile')
            ->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_update_business_profile(): void
    {
        $this->putJson('/api/settings/business-profile', [
            'name' => 'Unauthorized Business',
        ])->assertUnauthorized();
    }

    public function test_business_profile_update_validates_required_name(): void
    {
        $tenant = Tenant::factory()->create();

        $user = $this->createUserWithPermissions(
            $tenant,
            ['settings.update'],
        );

        $this->actingAs($user)
            ->putJson('/api/settings/business-profile', [
                'name' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_user_with_logo_permission_can_upload_business_logo(): void
{
    Storage::fake('public');

    $tenant = Tenant::factory()->create();

    $user = $this->createUserWithPermissions(
        $tenant,
        ['settings.update_logo']
    );

    $response = $this->actingAs($user)
        ->postJson('/api/settings/business-profile/logo', [
            'logo' => UploadedFile::fake()->image('business-logo.png'),
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => ['logo_path', 'logo_url'],
        ]);

    $tenant->refresh();

    $this->assertNotNull($tenant->logo_path);
    $this->assertStringStartsWith(
        "tenant-logos/{$tenant->id}/",
        $tenant->logo_path
    );

    Storage::disk('public')->assertExists($tenant->logo_path);
}
public function test_user_without_logo_permission_cannot_upload_business_logo(): void
{
    Storage::fake('public');

    $tenant = Tenant::factory()->create();

    $user = $this->createUserWithPermissions(
        $tenant,
        ['settings.view']
    );

    $response = $this->actingAs($user)
        ->postJson('/api/settings/business-profile/logo', [
            'logo' => UploadedFile::fake()->image('business-logo.png'),
        ]);

    $response->assertForbidden();

    $this->assertNull($tenant->fresh()->logo_path);

    $this->assertDatabaseMissing('tenants', [
        'id' => $tenant->id,
        'logo_path' => 'tenant-logos/' . $tenant->id . '/business-logo.png',
    ]);
}
public function test_business_logo_upload_rejects_invalid_file_type(): void
{
    Storage::fake('public');

    $tenant = Tenant::factory()->create();

    $user = $this->createUserWithPermissions(
        $tenant,
        ['settings.update_logo']
    );

    $response = $this->actingAs($user)
        ->postJson('/api/settings/business-profile/logo', [
            'logo' => UploadedFile::fake()->create(
                'document.pdf',
                100,
                'application/pdf'
            ),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('logo');

    $this->assertNull($tenant->fresh()->logo_path);
}
public function test_business_logo_upload_rejects_files_over_two_mb(): void
{
    Storage::fake('public');

    $tenant = Tenant::factory()->create();

    $user = $this->createUserWithPermissions(
        $tenant,
        ['settings.update_logo']
    );

    $response = $this->actingAs($user)
        ->postJson('/api/settings/business-profile/logo', [
            'logo' => UploadedFile::fake()->image('large-logo.png')
                ->size(2049),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('logo');

    $this->assertNull($tenant->fresh()->logo_path);
}
public function test_uploading_replacement_logo_deletes_old_logo(): void
{
    Storage::fake('public');

    $tenant = Tenant::factory()->create();

    $oldPath = "tenant-logos/{$tenant->id}/old-logo.png";

    Storage::disk('public')->put($oldPath, 'old-logo-content');

    $tenant->logo_path = $oldPath;
    $tenant->save();

    $user = $this->createUserWithPermissions(
        $tenant,
        ['settings.update_logo']
    );

    $response = $this->actingAs($user)
        ->postJson('/api/settings/business-profile/logo', [
            'logo' => UploadedFile::fake()->image('new-logo.png'),
        ]);

    $response->assertOk();

    $newPath = $tenant->fresh()->logo_path;

    $this->assertNotNull($newPath);
    $this->assertNotSame($oldPath, $newPath);

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($newPath);
}
public function test_user_with_logo_permission_can_remove_business_logo(): void
{
    Storage::fake('public');

    $tenant = Tenant::factory()->create();

    $logoPath = "tenant-logos/{$tenant->id}/business-logo.png";

    Storage::disk('public')->put($logoPath, 'logo-content');

    $tenant->logo_path = $logoPath;
    $tenant->save();

    $user = $this->createUserWithPermissions(
        $tenant,
        ['settings.update_logo']
    );

    $response = $this->actingAs($user)
        ->deleteJson('/api/settings/business-profile/logo');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.logo_path', null)
        ->assertJsonPath('data.logo_url', null);

    $this->assertNull($tenant->fresh()->logo_path);

    Storage::disk('public')->assertMissing($logoPath);
}
public function test_user_without_logo_permission_cannot_remove_business_logo(): void
{
    Storage::fake('public');

    $tenant = Tenant::factory()->create();

    $logoPath = "tenant-logos/{$tenant->id}/business-logo.png";

    Storage::disk('public')->put($logoPath, 'logo-content');

    $tenant->logo_path = $logoPath;
    $tenant->save();

    $user = $this->createUserWithPermissions(
        $tenant,
        ['settings.view']
    );

    $response = $this->actingAs($user)
        ->deleteJson('/api/settings/business-profile/logo');

    $response->assertForbidden();

    $this->assertSame($logoPath, $tenant->fresh()->logo_path);

    Storage::disk('public')->assertExists($logoPath);
}
public function test_tenant_cannot_remove_logo_outside_its_directory(): void
{
    Storage::fake('public');

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $logoPathB = "tenant-logos/{$tenantB->id}/business-logo.png";

    Storage::disk('public')->put($logoPathB, 'tenant-b-logo');

    // Simulate an invalid database path on tenant A.
    $tenantA->logo_path = $logoPathB;
    $tenantA->save();

    $userA = $this->createUserWithPermissions(
        $tenantA,
        ['settings.update_logo']
    );

    $response = $this->actingAs($userA)
        ->deleteJson('/api/settings/business-profile/logo');

    $response->assertForbidden();

    // The invalid reference and other tenant's file remain untouched.
    $this->assertSame($logoPathB, $tenantA->fresh()->logo_path);

    Storage::disk('public')->assertExists($logoPathB);
}
}
