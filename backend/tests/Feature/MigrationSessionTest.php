<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\MigrationSource;
use App\MigrationSessionStatus;
use App\Models\MigrationSession;
use App\Services\MigrationSessionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Services\MigrationCsvAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationSessionTest extends TestCase
{
    use RefreshDatabase;

public function test_migration_session_belongs_to_a_tenant(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = \App\Models\MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => 'quickbooks',
        'status' => 'pending',
    ]);

    $this->assertDatabaseHas('migration_sessions', [
        'id' => $session->id,
        'tenant_id' => $tenant->id,
        'source' => 'quickbooks',
        'status' => 'pending',
    ]);

    $this->assertTrue($session->tenant->is($tenant));
}
public function test_migration_session_is_tenant_scoped(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $session = \App\Models\MigrationSession::create([
        'tenant_id' => $tenantA->id,
        'created_by' => $userA->id,
        'source' => 'quickbooks',
        'status' => 'pending',
    ]);

    $this->assertTrue(
        \App\Models\MigrationSession::query()
            ->where('tenant_id', $tenantA->id)
            ->whereKey($session->id)
            ->exists()
    );

    $this->assertFalse(
        \App\Models\MigrationSession::query()
            ->where('tenant_id', $tenantB->id)
            ->whereKey($session->id)
            ->exists()
    );
}
public function test_migration_session_status_is_cast_to_enum(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = \App\Models\MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => 'quickbooks',
        'status' => \App\MigrationSessionStatus::PENDING,
    ]);

    $this->assertSame(
        \App\MigrationSessionStatus::PENDING,
        $session->status
    );
}
public function test_migration_session_source_is_cast_to_enum(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = \App\Models\MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => \App\MigrationSource::QUICKBOOKS,
        'status' => \App\MigrationSessionStatus::PENDING,
    ]);

    $this->assertSame(
        \App\MigrationSource::QUICKBOOKS,
        $session->source
    );
}
public function test_migration_session_stores_uploaded_file_metadata(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = \App\Models\MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => \App\MigrationSource::QUICKBOOKS,
        'status' => \App\MigrationSessionStatus::UPLOADED,
        'original_filename' => 'quickbooks-export.csv',
        'file_path' => 'migration-imports/quickbooks-export.csv',
        'file_size' => 245760,
        'mime_type' => 'text/csv',
    ]);

    $this->assertDatabaseHas('migration_sessions', [
        'id' => $session->id,
        'original_filename' => 'quickbooks-export.csv',
        'file_path' => 'migration-imports/quickbooks-export.csv',
        'file_size' => 245760,
        'mime_type' => 'text/csv',
    ]);
}
public function test_migration_session_service_creates_pending_quickbooks_session(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = app(MigrationSessionService::class)->create(
        $tenant->id,
        $user->id,
    );

    $this->assertDatabaseHas('migration_sessions', [
        'id' => $session->id,
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => 'quickbooks',
        'status' => 'pending',
    ]);
}
public function test_migration_session_can_attach_quickbooks_export_file(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = app(MigrationSessionService::class)->create(
        $tenant->id,
        $user->id,
    );

    $file = UploadedFile::fake()->create(
        'quickbooks-export.csv',
        100,
        'text/csv',
    );

    $session = app(MigrationSessionService::class)->attachFile(
        $session,
        $file,
    );

    $this->assertSame(
        \App\MigrationSessionStatus::UPLOADED,
        $session->status
    );

    $this->assertSame(
        'quickbooks-export.csv',
        $session->original_filename
    );

    $this->assertSame(
        'text/csv',
        $session->mime_type
    );

    $this->assertNotNull($session->file_path);
    $this->assertNotNull($session->file_size);

    Storage::disk('local')->assertExists($session->file_path);
}
public function test_migration_session_cannot_attach_another_file_after_upload(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $service = app(MigrationSessionService::class);

    $session = $service->create(
        $tenant->id,
        $user->id,
    );

    $firstFile = UploadedFile::fake()->create(
        'first-export.csv',
        100,
        'text/csv',
    );

    $service->attachFile($session, $firstFile);

    $secondFile = UploadedFile::fake()->create(
        'second-export.csv',
        100,
        'text/csv',
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
        'A migration file can only be attached to a pending migration session.'
    );

    $service->attachFile($session, $secondFile);
}
public function test_migration_session_rejects_non_csv_files(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $service = app(MigrationSessionService::class);

    $session = $service->create(
        $tenant->id,
        $user->id,
    );

    $file = UploadedFile::fake()->create(
        'quickbooks-export.pdf',
        100,
        'application/pdf',
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
        'Only CSV files are supported for QuickBooks migration.'
    );

    $service->attachFile($session, $file);

    Storage::disk('local')->assertMissing(
        $session->file_path ?? 'migration-imports/quickbooks-export.pdf'
    );
}
public function test_migration_session_rejects_files_over_10_mb(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $service = app(MigrationSessionService::class);

    $session = $service->create(
        $tenant->id,
        $user->id,
    );

    $file = UploadedFile::fake()->create(
        'large-quickbooks-export.csv',
        10 * 1024 + 1,
        'text/csv',
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
        'The QuickBooks migration file must not exceed 10 MB.'
    );

    $service->attachFile($session, $file);
}
public function test_uploaded_migration_session_can_begin_analysis(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $service = app(MigrationSessionService::class);

    $session = $service->create(
        $tenant->id,
        $user->id,
    );

    $file = UploadedFile::fake()->create(
        'quickbooks-export.csv',
        100,
        'text/csv',
    );

    $session = $service->attachFile($session, $file);

    $session = $service->beginAnalysis($session);

    $this->assertSame(
        \App\MigrationSessionStatus::ANALYZING,
        $session->status
    );

    $this->assertDatabaseHas('migration_sessions', [
        'id' => $session->id,
        'status' => 'analyzing',
    ]);
}
public function test_migration_session_cannot_begin_analysis_twice(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $service = app(MigrationSessionService::class);

    $session = $service->create(
        $tenant->id,
        $user->id,
    );

    $file = UploadedFile::fake()->create(
        'quickbooks-export.csv',
        100,
        'text/csv',
    );

    $session = $service->attachFile($session, $file);

    $service->beginAnalysis($session);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
        'Only uploaded migration sessions can begin analysis.'
    );

    $service->beginAnalysis($session->refresh());
}
public function test_migration_analysis_result_belongs_to_migration_session(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = \App\Models\MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => \App\MigrationSource::QUICKBOOKS,
        'status' => \App\MigrationSessionStatus::ANALYZING,
    ]);

    $result = \App\Models\MigrationAnalysisResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'row_count' => 10,
        'headers' => ['Name', 'Email', 'Phone'],
        'sample_rows' => [
            ['Name' => 'Test Customer', 'Email' => 'test@example.com'],
        ],
    ]);

    $this->assertTrue($result->migrationSession->is($session));
    $this->assertTrue($session->analysisResult->is($result));
}
public function test_migration_csv_analyzer_reads_headers_and_row_count(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $service = app(MigrationSessionService::class);

    $session = $service->create(
        $tenant->id,
        $user->id,
    );

    $csv = implode("\n", [
        'Name,Email,Phone',
        'John Doe,john@example.com,0240000000',
        'Jane Doe,jane@example.com,0200000000',
        'Bob Smith,bob@example.com,0500000000',
    ]);

    $path = 'migration-imports/quickbooks-export.csv';

    Storage::disk('local')->put($path, $csv);

    $session->update([
        'status' => \App\MigrationSessionStatus::UPLOADED,
        'file_path' => $path,
        'original_filename' => 'quickbooks-export.csv',
        'file_size' => strlen($csv),
        'mime_type' => 'text/csv',
    ]);

    $result = app(MigrationCsvAnalyzer::class)->analyze($session);

    $this->assertSame(
        ['Name', 'Email', 'Phone'],
        $result['headers']
    );

    $this->assertSame(3, $result['row_count']);
}
public function test_migration_session_analysis_is_persisted(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $service = app(MigrationSessionService::class);

    $session = $service->create(
        $tenant->id,
        $user->id,
    );

    $csv = implode("\n", [
        'Name,Email,Phone',
        'John Doe,john@example.com,0240000000',
        'Jane Doe,jane@example.com,0200000000',
    ]);

    $path = 'migration-imports/quickbooks-export.csv';

    Storage::disk('local')->put($path, $csv);

    $session->update([
        'status' => \App\MigrationSessionStatus::UPLOADED,
        'file_path' => $path,
        'original_filename' => 'quickbooks-export.csv',
        'file_size' => strlen($csv),
        'mime_type' => 'text/csv',
    ]);

    $result = app(MigrationCsvAnalyzer::class)->analyze($session);

    $analysis = \App\Models\MigrationAnalysisResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => null,
        'row_count' => $result['row_count'],
        'headers' => $result['headers'],
        'sample_rows' => null,
    ]);

    $this->assertDatabaseHas('migration_analysis_results', [
        'id' => $analysis->id,
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'row_count' => 2,
    ]);

    $this->assertSame(
        ['Name', 'Email', 'Phone'],
        $analysis->headers
    );
}
public function test_migration_session_service_analyzes_and_persists_csv(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $service = app(MigrationSessionService::class);

    $session = $service->create(
        $tenant->id,
        $user->id,
    );

    $csv = implode("\n", [
        'Name,Email,Phone',
        'John Doe,john@example.com,0240000000',
        'Jane Doe,jane@example.com,0200000000',
    ]);

    $path = 'migration-imports/quickbooks-export.csv';

    Storage::disk('local')->put($path, $csv);

    $session->update([
        'status' => \App\MigrationSessionStatus::UPLOADED,
        'file_path' => $path,
        'original_filename' => 'quickbooks-export.csv',
        'file_size' => strlen($csv),
        'mime_type' => 'text/csv',
    ]);

    $analysis = $service->analyze($session);

    $this->assertDatabaseHas('migration_analysis_results', [
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'row_count' => 2,
    ]);

    $this->assertSame(
        ['Name', 'Email', 'Phone'],
        $analysis->headers
    );

    $this->assertSame(
        \App\MigrationSessionStatus::ANALYZING,
        $session->refresh()->status
    );
}
public function test_migration_csv_analyzer_returns_up_to_five_sample_rows(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = app(MigrationSessionService::class)->create(
        $tenant->id,
        $user->id,
    );

    $csv = implode("\n", [
        'Name,Email,Phone',
        'Customer 1,one@example.com,0240000001',
        'Customer 2,two@example.com,0240000002',
        'Customer 3,three@example.com,0240000003',
        'Customer 4,four@example.com,0240000004',
        'Customer 5,five@example.com,0240000005',
        'Customer 6,six@example.com,0240000006',
    ]);

    $path = 'migration-imports/quickbooks-export.csv';

    Storage::disk('local')->put($path, $csv);

    $session->update([
        'status' => \App\MigrationSessionStatus::UPLOADED,
        'file_path' => $path,
        'original_filename' => 'quickbooks-export.csv',
        'file_size' => strlen($csv),
        'mime_type' => 'text/csv',
    ]);

    $result = app(MigrationCsvAnalyzer::class)->analyze($session);

    $this->assertCount(5, $result['sample_rows']);

    $this->assertSame(
        ['Name' => 'Customer 1', 'Email' => 'one@example.com', 'Phone' => '0240000001'],
        $result['sample_rows'][0]
    );

    $this->assertSame(
        ['Name' => 'Customer 5', 'Email' => 'five@example.com', 'Phone' => '0240000005'],
        $result['sample_rows'][4]
    );
}
public function test_it_detects_a_quickbooks_customer_csv_entity_type(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'file_path' => 'migration-imports/customers.csv',
    ]);

    Storage::disk('local')->put(
        $session->file_path,
        implode("\n", [
            'Customer,Email,Phone,Company',
            'John Doe,john@example.com,0244000000,John Doe Ltd',
            'Jane Doe,jane@example.com,0244111111,Jane Doe Ltd',
        ])
    );

    $result = app(MigrationCsvAnalyzer::class)->analyze($session);

    $this->assertSame('customers', $result['entity_type']);
}
public function test_it_persists_detected_entity_type_in_analysis_result(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'file_path' => 'migration-imports/customers.csv',
    ]);

    Storage::disk('local')->put(
        $session->file_path,
        implode("\n", [
            'Customer,Email,Phone,Company',
            'John Doe,john@example.com,0244000000,John Doe Ltd',
            'Jane Doe,jane@example.com,0244111111,Jane Doe Ltd',
        ])
    );

    app(MigrationSessionService::class)->analyze($session);

    $this->assertDatabaseHas('migration_analysis_results', [
        'migration_session_id' => $session->id,
        'tenant_id' => $tenant->id,
        'entity_type' => 'customers',
    ]);
}
public function test_it_detects_a_quickbooks_supplier_csv_entity_type(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'file_path' => 'migration-imports/vendors.csv',
    ]);

    Storage::disk('local')->put(
        $session->file_path,
        implode("\n", [
            'Vendor,Email,Phone,Company',
            'ABC Supplies,abc@example.com,0244000000,ABC Supplies Ltd',
            'XYZ Traders,xyz@example.com,0244111111,XYZ Traders Ltd',
        ])
    );

    $result = app(MigrationCsvAnalyzer::class)->analyze($session);

    $this->assertSame('suppliers', $result['entity_type']);
}
public function test_it_detects_a_quickbooks_product_service_csv_entity_type(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'file_path' => 'migration-imports/products.csv',
    ]);

    Storage::disk('local')->put(
        $session->file_path,
        implode("\n", [
            'Product/Service Name,SKU,Sales Price,Cost',
            'Premium Coffee,COF-001,50.00,30.00',
            'Consulting Service,SVC-001,500.00,0.00',
        ])
    );

    $result = app(MigrationCsvAnalyzer::class)->analyze($session);

    $this->assertSame('catalog_items', $result['entity_type']);
}
public function test_it_detects_a_quickbooks_invoice_csv_entity_type(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'file_path' => 'migration-imports/invoices.csv',
    ]);

    Storage::disk('local')->put(
        $session->file_path,
        implode("\n", [
            'Invoice Number,Customer,Invoice Date,Due Date,Amount',
            'INV-1001,John Doe,2026-09-01,2026-09-30,500.00',
            'INV-1002,Jane Doe,2026-09-02,2026-10-01,750.00',
        ])
    );

    $result = app(MigrationCsvAnalyzer::class)->analyze($session);

    $this->assertSame('invoices', $result['entity_type']);
}
public function test_it_detects_a_quickbooks_expense_csv_entity_type(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'file_path' => 'migration-imports/expenses.csv',
    ]);

    Storage::disk('local')->put(
        $session->file_path,
        implode("\n", [
            'Date,Payee,Category,Amount,Payment Method',
            '2026-09-01,MTN Ghana,Utilities,250.00,Mobile Money',
            '2026-09-02,Office Supplies Ltd,Office Supplies,450.00,Bank Transfer',
        ])
    );

    $result = app(MigrationCsvAnalyzer::class)->analyze($session);

    $this->assertSame('expenses', $result['entity_type']);
}

public function test_it_detects_a_quickbooks_customer_csv_without_email(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'file_path' => 'migration-imports/customers.csv',
    ]);

    Storage::disk('local')->put(
        $session->file_path,
        implode("\n", [
            'Customer,Phone,Company',
            'John Doe,0244000000,John Doe Ltd',
            'Jane Doe,0244111111,Jane Doe Ltd',
        ])
    );

    $result = app(MigrationCsvAnalyzer::class)->analyze($session);

    $this->assertSame('customers', $result['entity_type']);
}
public function test_it_detects_a_quickbooks_payment_csv_entity_type(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'file_path' => 'migration-imports/payments.csv',
    ]);

    Storage::disk('local')->put(
        $session->file_path,
        implode("\n", [
            'Payment Date,Customer,Invoice Number,Amount,Payment Method',
            '2026-09-05,John Doe,INV-1001,250.00,Mobile Money',
            '2026-09-06,Jane Doe,INV-1002,500.00,Bank Transfer',
        ])
    );

    $result = app(MigrationCsvAnalyzer::class)->analyze($session);

    $this->assertSame('payments', $result['entity_type']);
}
public function test_it_returns_null_for_an_unrecognized_csv_entity_type(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'file_path' => 'migration-imports/unknown.csv',
    ]);

    Storage::disk('local')->put(
        $session->file_path,
        implode("\n", [
            'Random Field,Another Field,Value',
            'Something,Something Else,123',
        ])
    );

    $result = app(MigrationCsvAnalyzer::class)->analyze($session);

    $this->assertNull($result['entity_type']);
}
}