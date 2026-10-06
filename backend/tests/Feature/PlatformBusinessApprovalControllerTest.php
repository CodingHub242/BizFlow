<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Models\PlatformAuditLog;
use App\TenantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformBusinessApprovalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_view_pending_businesses(): void
    {
       $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        Tenant::factory()->create([
            'name' => 'Pending Business',
            'status' => TenantStatus::PENDING,
        ]);

        $this->actingAs($admin, 'platform')
            ->get('/platform/businesses/pending')
            ->assertOk();
    }

public function test_tenant_user_cannot_view_pending_businesses(): void
{
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $user = \App\Models\User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->actingAs($user, 'web')
        ->get('/platform/businesses/pending')
        ->assertRedirect(route('platform.login'));
}
public function test_pending_business_list_only_contains_pending_businesses(): void
{
   $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    Tenant::factory()->create([
        'name' => 'Pending Business',
        'status' => TenantStatus::PENDING,
    ]);

    Tenant::factory()->create([
        'name' => 'Approved Business',
        'status' => TenantStatus::APPROVED,
    ]);

    Tenant::factory()->create([
        'name' => 'Rejected Business',
        'status' => TenantStatus::REJECTED,
    ]);

    Tenant::factory()->create([
        'name' => 'Suspended Business',
        'status' => TenantStatus::SUSPENDED,
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->get('/platform/businesses/pending');

    $response
        ->assertOk()
        ->assertViewHas('businesses', function ($businesses) {
            return $businesses->count() === 1
                && $businesses->first()->name === 'Pending Business'
                && $businesses->first()->status === TenantStatus::PENDING;
        });
}
public function test_platform_admin_can_approve_pending_business(): void
{
   $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'name' => 'Pending Business',
        'status' => TenantStatus::PENDING,
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->post(
            "/platform/businesses/{$tenant->id}/approve"
        );

    $response
        ->assertRedirect(route('platform.businesses.pending'))
        ->assertSessionHas('success');

    $tenant->refresh();

    $this->assertSame(
        TenantStatus::APPROVED,
        $tenant->status
    );
}
public function test_platform_admin_cannot_approve_non_pending_business(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->post(
            "/platform/businesses/{$tenant->id}/approve"
        );

    $response->assertSessionHasErrors();

    $tenant->refresh();

    $this->assertSame(
        TenantStatus::APPROVED,
        $tenant->status
    );
}
public function test_tenant_user_cannot_approve_business(): void
{
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $user = \App\Models\User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $pendingBusiness = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $this->actingAs($user, 'web')
        ->post(
            "/platform/businesses/{$pendingBusiness->id}/approve"
        )
        ->assertRedirect(route('platform.login'));

    $pendingBusiness->refresh();

    $this->assertSame(
        TenantStatus::PENDING,
        $pendingBusiness->status
    );
}
public function test_platform_admin_can_reject_pending_business(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'name' => 'Pending Business',
        'status' => TenantStatus::PENDING,
    ]);

    $reason = 'Business registration information could not be verified.';

    $response = $this->actingAs($admin, 'platform')
        ->post(
            "/platform/businesses/{$tenant->id}/reject",
            ['reason' => $reason]
        );

    $response
        ->assertRedirect(route('platform.businesses.pending'))
        ->assertSessionHas('success');

    $tenant->refresh();

    $this->assertSame(
        TenantStatus::REJECTED,
        $tenant->status
    );

    $this->assertSame(
        $reason,
        $tenant->rejection_reason
    );
}
public function test_platform_admin_cannot_reject_without_a_reason(): void
{
     $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->post(
            "/platform/businesses/{$tenant->id}/reject",
            ['reason' => '']
        );

    $response->assertSessionHasErrors('reason');

    $tenant->refresh();

    $this->assertSame(
        TenantStatus::PENDING,
        $tenant->status
    );
}
public function test_tenant_user_cannot_reject_business(): void
{
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $user = \App\Models\User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $pendingBusiness = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $this->actingAs($user, 'web')
        ->post(
            "/platform/businesses/{$pendingBusiness->id}/reject",
            ['reason' => 'Should not be allowed.']
        )
        ->assertRedirect(route('platform.login'));

    $pendingBusiness->refresh();

    $this->assertSame(
        TenantStatus::PENDING,
        $pendingBusiness->status
    );
}
public function test_platform_admin_can_suspend_approved_business(): void
{
     $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'name' => 'Approved Business',
        'status' => TenantStatus::APPROVED,
    ]);

    $reason = 'Business account suspended following a compliance review.';

    $response = $this->actingAs($admin, 'platform')
        ->post(
            "/platform/businesses/{$tenant->id}/suspend",
            ['reason' => $reason]
        );

    $response
        ->assertRedirect(route('platform.businesses.pending'))
        ->assertSessionHas('success');

    $tenant->refresh();

    $this->assertSame(
        TenantStatus::SUSPENDED,
        $tenant->status
    );

    $this->assertSame(
        $reason,
        $tenant->suspension_reason
    );
}
public function test_platform_admin_cannot_suspend_without_a_reason(): void
{
     $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->post(
            "/platform/businesses/{$tenant->id}/suspend",
            ['reason' => '']
        );

    $response->assertSessionHasErrors('reason');

    $tenant->refresh();

    $this->assertSame(
        TenantStatus::APPROVED,
        $tenant->status
    );
}
public function test_tenant_user_cannot_suspend_business(): void
{
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $user = \App\Models\User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $approvedBusiness = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $this->actingAs($user, 'web')
        ->post(
            "/platform/businesses/{$approvedBusiness->id}/suspend",
            ['reason' => 'Should not be allowed.']
        )
        ->assertRedirect(route('platform.login'));

    $approvedBusiness->refresh();

    $this->assertSame(
        TenantStatus::APPROVED,
        $approvedBusiness->status
    );
}
public function test_pending_businesses_are_rendered_in_the_view(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    Tenant::factory()->create([
        'name' => 'Acme Construction',
        'email' => 'acme@example.com',
        'phone' => '0244000000',
        'business_type' => 'Construction',
        'status' => TenantStatus::PENDING,
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->get('/platform/businesses/pending');

    $response
        ->assertOk()
        ->assertSee('Acme Construction')
        ->assertSee('acme@example.com')
        ->assertSee('0244000000')
        ->assertSee('Construction')
        ->assertSee('Pending');
}
public function test_platform_admin_can_view_business_review_page(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'name' => 'Acme Construction',
        'email' => 'acme@example.com',
        'phone' => '0244000000',
        'business_type' => 'Construction',
        'status' => TenantStatus::PENDING,
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->get("/platform/businesses/{$tenant->id}");

    $response
        ->assertOk()
        ->assertSee('Acme Construction')
        ->assertSee('acme@example.com')
        ->assertSee('0244000000')
        ->assertSee('Construction')
        ->assertSee('Pending');
}
public function test_tenant_user_cannot_view_business_review_page(): void
{
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherTenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $response = $this->actingAs($user, 'web')
        ->get("/platform/businesses/{$otherTenant->id}");

    $response->assertRedirect(route('platform.login'));
}
public function test_business_review_page_contains_approve_form(): void
{
     $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->get("/platform/businesses/{$tenant->id}");

    $response
        ->assertOk()
        ->assertSee(
            route('platform.businesses.approve', $tenant),
            false
        )
        ->assertSee('Approve Business');
}
public function test_business_review_page_contains_reject_form(): void
{
     $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->get("/platform/businesses/{$tenant->id}");

    $response
        ->assertOk()
        ->assertSee(
            route('platform.businesses.reject', $tenant),
            false
        )
        ->assertSee('Reject Business')
        ->assertSee('Reason for rejection');
}
public function test_platform_admin_can_view_platform_dashboard(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);
    $response = $this->actingAs($admin, 'platform')
        ->get('/platform/dashboard');

    $response
        ->assertOk()
        ->assertSee('Platform Dashboard');
}
public function test_tenant_user_cannot_view_platform_dashboard(): void
{
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this->actingAs($user, 'web')
        ->get('/platform/dashboard');

    $response->assertRedirect(route('platform.login'));
}
public function test_platform_dashboard_displays_business_counts(): void
{
     $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    Tenant::factory()->count(3)->create([
        'status' => TenantStatus::PENDING,
    ]);

    Tenant::factory()->count(5)->create([
        'status' => TenantStatus::APPROVED,
    ]);

    Tenant::factory()->count(2)->create([
        'status' => TenantStatus::REJECTED,
    ]);

    Tenant::factory()->count(1)->create([
        'status' => TenantStatus::SUSPENDED,
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->get('/platform/dashboard');

    $response
        ->assertOk()
        ->assertSee('Pending Businesses')
        ->assertSee('Approved Businesses')
        ->assertSee('Rejected Businesses')
        ->assertSee('Suspended Businesses')
        ->assertSee('3')
        ->assertSee('5')
        ->assertSee('2')
        ->assertSee('1');
}
public function test_platform_dashboard_displays_recent_business_registrations(): void
{
   $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    Tenant::factory()->create([
        'name' => 'Accra Auto Works',
        'business_type' => 'Automotive',
        'status' => TenantStatus::PENDING,
    ]);

    Tenant::factory()->create([
        'name' => 'Kumasi Digital Services',
        'business_type' => 'Technology',
        'status' => TenantStatus::APPROVED,
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->get('/platform/dashboard');

    $response
        ->assertOk()
        ->assertSee('Accra Auto Works')
        ->assertSee('Automotive')
        ->assertSee('Kumasi Digital Services')
        ->assertSee('Technology');
}
public function test_platform_admin_can_view_audit_logs(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'name' => 'Acme Construction',
        'status' => TenantStatus::APPROVED,
    ]);

   PlatformAuditLog::create([
    'platform_admin_id' => $admin->id,
    'action' => 'business.approved',
    'target_type' => Tenant::class,
    'target_id' => $tenant->id,
    'reason' => null,
    'metadata' => null,
]);

    $response = $this->actingAs($admin, 'platform')
        ->get('/platform/audit-logs');

    $response
        ->assertOk()
        ->assertSee('Audit Logs')
        ->assertSee('business.approved')
        ->assertSee('Acme Construction');
}
public function test_tenant_user_cannot_view_platform_audit_logs(): void
{
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this->actingAs($user, 'web')
        ->get('/platform/audit-logs');

    $response->assertRedirect(route('platform.login'));
}
public function test_platform_audit_logs_are_paginated(): void
{
    $admin = PlatformAdmin::create([
        'name' => 'Platform Administrator',
        'email' => 'admin@bizflow.test',
        'password' => 'SecurePassword123!',
    ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    for ($i = 1; $i <= 55; $i++) {
        PlatformAuditLog::create([
            'platform_admin_id' => $admin->id,
            'action' => 'business.approved',
            'target_type' => Tenant::class,
            'target_id' => $tenant->id,
            'reason' => null,
            'metadata' => null,
        ]);
    }

    $response = $this->actingAs($admin, 'platform')
        ->get('/platform/audit-logs');

    $response
        ->assertOk()
        ->assertSee('Audit Logs');

    $this->assertSame(50, $response->viewData('logs')->count());
}
public function test_platform_admin_can_filter_audit_logs_by_action(): void
{
    $admin = PlatformAdmin::create([
        'name' => 'Platform Administrator',
        'email' => 'admin@bizflow.test',
        'password' => 'SecurePassword123!',
    ]);

    $approvedBusiness = Tenant::factory()->create([
        'name' => 'Approved Construction',
        'status' => TenantStatus::APPROVED,
    ]);

    $rejectedBusiness = Tenant::factory()->create([
        'name' => 'Rejected Stores',
        'status' => TenantStatus::REJECTED,
    ]);

    PlatformAuditLog::create([
        'platform_admin_id' => $admin->id,
        'action' => 'business.approved',
        'target_type' => Tenant::class,
        'target_id' => $approvedBusiness->id,
    ]);

    PlatformAuditLog::create([
        'platform_admin_id' => $admin->id,
        'action' => 'business.rejected',
        'target_type' => Tenant::class,
        'target_id' => $rejectedBusiness->id,
        'reason' => 'Registration information requires clarification.',
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->get('/platform/audit-logs?action=business.rejected');

    $response
        ->assertOk()
        ->assertSee('business.rejected')
        ->assertSee('Rejected Stores')
        ->assertDontSee('Approved Construction');
}
public function test_audit_log_action_filter_is_rendered_and_selected(): void
{
    $admin = PlatformAdmin::create([
        'name' => 'Platform Administrator',
        'email' => 'admin@bizflow.test',
        'password' => 'SecurePassword123!',
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->get('/platform/audit-logs?action=business.rejected');

    $response
        ->assertOk()
        ->assertSee('Filter by action')
        ->assertSee('business.rejected')
        ->assertSee('selected', false);
}
public function test_platform_admin_filter_is_preserved_across_pagination(): void
{
    $admin = PlatformAdmin::create([
        'name' => 'Platform Administrator',
        'email' => 'admin@bizflow.test',
        'password' => 'SecurePassword123!',
    ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::REJECTED,
    ]);

    // Create enough rejected logs to force pagination.
   

         PlatformAuditLog::create([
        'platform_admin_id' => $admin->id,
        'action' => 'business.rejected',
        'target_type' => Tenant::class,
        'target_id' => $tenant->id,
    ]);

    // Add another action that should not be included.
    PlatformAuditLog::create([
        'platform_admin_id' => $admin->id,
        'action' => 'business.approved',
        'target_type' => Tenant::class,
        'target_id' => $tenant->id,
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->get('/platform/audit-logs?action=business.rejected&page=2');

    $response
        ->assertOk()
        ->assertViewHas('action', 'business.rejected')
       ->assertSee('business.rejected', false);
}
}