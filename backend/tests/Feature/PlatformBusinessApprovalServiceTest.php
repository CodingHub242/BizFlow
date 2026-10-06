<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Services\Platform\BusinessApprovalService;
use App\Services\Platform\PlatformAuditService;
use App\TenantStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformBusinessApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_business_can_be_approved_by_platform_admin(): void
    {
        //$admin = PlatformAdmin::factory()->create();
         $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::PENDING,
        ]);

        $service = app(BusinessApprovalService::class);

        $service->approve($tenant, $admin);

        $tenant->refresh();

        $this->assertSame(
            TenantStatus::APPROVED,
            $tenant->status
        );
    }

    public function test_approved_business_cannot_be_approved_again(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $reason = '';
    $service = app(BusinessApprovalService::class);

    $this->expectException(\DomainException::class);
    $this->expectExceptionMessage(
        'Only pending businesses can be approved.'
    );

    $service->approve($tenant, $admin,'');
}
public function test_rejected_business_cannot_be_approved(): void
{
   $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::REJECTED,
    ]);

    $service = app(BusinessApprovalService::class);

    $this->expectException(\DomainException::class);
    $this->expectExceptionMessage(
        'Only pending businesses can be approved.'
    );

    $service->approve($tenant, $admin);
}
public function test_suspended_business_cannot_be_approved(): void
{
   $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::SUSPENDED,
    ]);

    $service = app(BusinessApprovalService::class);

    $this->expectException(\DomainException::class);
    $this->expectExceptionMessage(
        'Only pending businesses can be approved.'
    );

    $service->approve($tenant, $admin);
}
public function test_pending_business_can_be_rejected_with_a_reason(): void
{
   $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);


    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $reason = 'Business registration information could not be verified.';
    $service = app(BusinessApprovalService::class);
  
    $service->reject(
        $tenant,
        $admin,
        $reason
    );

    $tenant->refresh();

    $this->assertSame(
        TenantStatus::REJECTED,
        $tenant->status
    );
}
public function test_business_rejection_requires_a_reason(): void
{
   $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $service = app(BusinessApprovalService::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'A rejection reason is required.'
    );

    $service->reject($tenant, $admin, '');
}
public function test_approved_business_cannot_be_rejected(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $service = app(BusinessApprovalService::class);

    $this->expectException(\DomainException::class);
    $this->expectExceptionMessage(
        'Only pending businesses can be rejected.'
    );

    $service->reject(
        $tenant,
        $admin,
        'This should not be allowed.'
    );
}
public function test_approval_records_platform_admin_and_timestamp(): void
{
     $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $service = app(BusinessApprovalService::class);

    $service->approve($tenant, $admin);

    $tenant->refresh();

    $this->assertSame(
        TenantStatus::APPROVED,
        $tenant->status
    );

    $this->assertSame(
        $admin->id,
        $tenant->approved_by
    );

    $this->assertNotNull($tenant->approved_at);
}
public function test_rejection_records_platform_admin_timestamp_and_reason(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $reason = 'Business registration information could not be verified.';

    $service = app(BusinessApprovalService::class);

    $service->reject($tenant, $admin, $reason);

    $tenant->refresh();

    $this->assertSame(
        TenantStatus::REJECTED,
        $tenant->status
    );

    $this->assertSame(
        $admin->id,
        $tenant->rejected_by
    );

    $this->assertNotNull($tenant->rejected_at);

    $this->assertSame(
        $reason,
        $tenant->rejection_reason
    );
}
public function test_approval_creates_platform_audit_log(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $service = app(BusinessApprovalService::class);

    $service->approve($tenant, $admin);

    $this->assertDatabaseHas('platform_audit_logs', [
        'platform_admin_id' => $admin->id,
        'action' => 'business.approved',
        'target_type' => Tenant::class,
        'target_id' => $tenant->id,
    ]);
}
public function test_rejection_creates_platform_audit_log(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $reason = 'Business information could not be verified.';

    $service = app(BusinessApprovalService::class);

    $service->reject($tenant, $admin, $reason);

    $this->assertDatabaseHas('platform_audit_logs', [
        'platform_admin_id' => $admin->id,
        'action' => 'business.rejected',
        'target_type' => Tenant::class,
        'target_id' => $tenant->id,
        'reason' => $reason,
    ]);
}
public function test_approval_rolls_back_if_audit_logging_fails(): void
{
   $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

   $auditService = \Mockery::mock(PlatformAuditService::class);

$auditService
    ->shouldReceive('record')
    ->once()
    ->andThrow(new \RuntimeException('Audit logging failed.'));

$this->app->instance(
    PlatformAuditService::class,
    $auditService
);

$this->app->forgetInstance(BusinessApprovalService::class);

$service = app(BusinessApprovalService::class);

    try {
        $service->approve($tenant, $admin);

        $this->fail('Expected audit logging to fail.');
    } catch (\RuntimeException $exception) {
        $this->assertSame(
            'Audit logging failed.',
            $exception->getMessage()
        );
    }

    $tenant->refresh();

    $this->assertSame(
        TenantStatus::PENDING,
        $tenant->status
    );

    $this->assertNull($tenant->approved_at);
    $this->assertNull($tenant->approved_by);

    $this->assertDatabaseHas('tenants', [
        'id' => $tenant->id,
        'status' => TenantStatus::PENDING->value,
        'approved_at' => null,
        'approved_by' => null,
    ]);
}
public function test_approved_business_can_be_suspended_with_a_reason(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $service = app(BusinessApprovalService::class);

    $reason = 'Business account suspended following a compliance review.';

    $service->suspend($tenant, $admin, $reason);

    $tenant->refresh();

    $this->assertSame(
        TenantStatus::SUSPENDED,
        $tenant->status
    );

    $this->assertSame(
        $admin->id,
        $tenant->suspended_by
    );

    $this->assertNotNull($tenant->suspended_at);

    $this->assertSame(
        $reason,
        $tenant->suspension_reason
    );
}
public function test_business_suspension_requires_a_reason(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $service = app(BusinessApprovalService::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'A suspension reason is required.'
    );

    $service->suspend($tenant, $admin, '');
}
public function test_pending_business_cannot_be_suspended(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PENDING,
    ]);

    $service = app(BusinessApprovalService::class);

    $this->expectException(\DomainException::class);
    $this->expectExceptionMessage(
        'Only approved businesses can be suspended.'
    );

    $service->suspend(
        $tenant,
        $admin,
        'This should not be allowed.'
    );
}
public function test_rejected_business_cannot_be_suspended(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::REJECTED,
    ]);

    $service = app(BusinessApprovalService::class);

    $this->expectException(\DomainException::class);
    $this->expectExceptionMessage(
        'Only approved businesses can be suspended.'
    );

    $service->suspend(
        $tenant,
        $admin,
        'This should not be allowed.'
    );
}
public function test_suspended_business_cannot_be_suspended_again(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::SUSPENDED,
    ]);

    $service = app(BusinessApprovalService::class);

    $this->expectException(\DomainException::class);
    $this->expectExceptionMessage(
        'Only approved businesses can be suspended.'
    );

    $service->suspend(
        $tenant,
        $admin,
        'This should not be allowed.'
    );
}
public function test_suspension_creates_platform_audit_log(): void
{
    $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);

    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $reason = 'Business account suspended following a compliance review.';

    $service = app(BusinessApprovalService::class);

    $service->suspend($tenant, $admin, $reason);

    $this->assertDatabaseHas('platform_audit_logs', [
        'platform_admin_id' => $admin->id,
        'action' => 'business.suspended',
        'target_type' => Tenant::class,
        'target_id' => $tenant->id,
        'reason' => $reason,
    ]);
}
public function test_suspension_rolls_back_if_audit_logging_fails(): void
{
     $admin = PlatformAdmin::create([
            'name' => 'Platform Administrator',
            'email' => 'admin@bizflow.test',
            'password' => 'SecurePassword123!',
        ]);


    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::APPROVED,
    ]);

    $auditService = \Mockery::mock(PlatformAuditService::class);

    $auditService
        ->shouldReceive('record')
        ->once()
        ->andThrow(new \RuntimeException('Audit logging failed.'));

    $this->app->instance(
        PlatformAuditService::class,
        $auditService
    );

    $this->app->forgetInstance(BusinessApprovalService::class);

    $service = app(BusinessApprovalService::class);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Audit logging failed.');

    try {
        $service->suspend(
            $tenant,
            $admin,
            'Compliance review.'
        );
    } finally {
        $tenant->refresh();

        $this->assertSame(
            TenantStatus::APPROVED,
            $tenant->status
        );

        $this->assertNull($tenant->suspended_at);
        $this->assertNull($tenant->suspended_by);
        $this->assertNull($tenant->suspension_reason);
    }
}
}