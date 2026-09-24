<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Expense;
use App\Models\Customer;
use App\Models\Category;
use App\Models\Supplier;
use App\MigrationSource;
use App\InvoicePaymentMethod;
use App\Models\CatalogItem;
use App\Services\MigrationInvoicePaymentImporter;
use App\Services\MigrationImportService;
use App\Services\MigrationCatalogItemImporter;
use App\Services\MigrationInvoiceImporter;
use App\Services\MigrationExpenseImporter;
use App\MigrationSessionStatus;
use App\Models\Branch;
use App\Models\Invoice;
use App\Services\MigrationCustomerResolver;
use App\Services\MigrationInvoiceResolver;
use App\Services\MigrationCategoryResolver;
use App\CatalogItemType;
use App\Services\MigrationCatalogItemCreator;
use App\Services\MigrationInvoicePaymentCreator;
use App\Models\MigrationSession;
use App\Models\MigrationMapping;
use App\Models\MigrationImportBatch;
use App\Services\MigrationCustomerImporter;
use App\Services\MigrationSupplierImporter;
use App\Models\MigrationValidationResult;
use App\Services\MigrationSessionService;
use App\Services\MigrationCustomerCreator;
use App\Services\MigrationExpenseCreator;
use App\Services\MigrationInvoiceCreator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Services\MigrationCsvAnalyzer;
use RuntimeException;
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
public function test_migration_mapping_belongs_to_migration_session_and_casts_field_mapping(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
    ]);

    $mapping = MigrationMapping::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'field_mapping' => [
            'Customer' => 'name',
            'Email' => 'email',
            'Phone' => 'phone',
            'Company' => 'company_name',
        ],
    ]);

    $this->assertInstanceOf(
        MigrationSession::class,
        $mapping->migrationSession
    );

    $this->assertIsArray($mapping->field_mapping);

    $this->assertSame(
        'name',
        $mapping->field_mapping['Customer']
    );

    $this->assertSame(
        $mapping->id,
        $session->mapping->id
    );
}
public function test_migration_mapping_is_tenant_scoped(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $sessionA = MigrationSession::factory()->create([
        'tenant_id' => $tenantA->id,
        'created_by' => $userA->id,
    ]);

    $sessionB = MigrationSession::factory()->create([
        'tenant_id' => $tenantB->id,
        'created_by' => $userB->id,
    ]);

    $mapping = MigrationMapping::create([
        'tenant_id' => $tenantA->id,
        'migration_session_id' => $sessionA->id,
        'entity_type' => 'customers',
        'field_mapping' => [
            'Customer' => 'name',
        ],
    ]);

    $this->assertSame($tenantA->id, $mapping->tenant_id);
    $this->assertSame($tenantA->id, $sessionA->tenant_id);

    $this->assertNull(
        MigrationMapping::where('tenant_id', $tenantB->id)
            ->where('migration_session_id', $sessionA->id)
            ->first()
    );

    $this->assertNull($sessionB->mapping);
}
public function test_migration_mapping_service_generates_customer_field_mapping(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $headers = [
        'Customer',
        'Email',
        'Phone',
        'Company',
    ];

    $mapping = $service->generate('customers', $headers);

    $this->assertSame([
        'Customer' => 'name',
        'Email' => 'email',
        'Phone' => 'phone',
        'Company' => 'company_name',
    ], $mapping);
}
public function test_migration_mapping_service_generates_supplier_field_mapping(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $headers = [
        'Vendor',
        'Email',
        'Phone',
        'Company',
    ];

    $mapping = $service->generate('suppliers', $headers);

    $this->assertSame([
        'Vendor' => 'name',
        'Email' => 'email',
        'Phone' => 'phone',
        'Company' => 'company_name',
    ], $mapping);
}
public function test_migration_mapping_service_generates_catalog_item_field_mapping(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $headers = [
        'Product/Service Name',
        'SKU',
        'Description',
        'Sales Price',
        'Cost',
    ];

    $mapping = $service->generate('catalog_items', $headers);

    $this->assertSame([
        'Product/Service Name' => 'name',
        'SKU' => 'sku',
        'Description' => 'description',
        'Sales Price' => 'selling_price',
        'Cost' => 'cost_price',
    ], $mapping);
}
public function test_migration_mapping_service_generates_invoice_field_mapping(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $headers = [
        'Invoice Number',
        'Customer',
        'Invoice Date',
        'Due Date',
        'Amount',
    ];

    $mapping = $service->generate('invoices', $headers);

    $this->assertSame([
        'Invoice Number' => 'invoice_number',
        'Customer' => 'customer_id',
        'Invoice Date' => 'issued_at',
        'Due Date' => 'due_at',
        'Amount' => 'total',
    ], $mapping);
}
public function test_migration_mapping_service_generates_expense_field_mapping(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $headers = [
        'Date',
        'Payee',
        'Category',
        'Amount',
        'Payment Method',
    ];

    $mapping = $service->generate('expenses', $headers);

    $this->assertSame([
        'Date' => 'expense_date',
        'Payee' => 'description',
        'Category' => 'category_id',
        'Amount' => 'amount',
        'Payment Method' => 'payment_method',
    ], $mapping);
}
public function test_migration_mapping_service_generates_payment_field_mapping(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $headers = [
        'Payment Date',
        'Customer',
        'Invoice Number',
        'Amount',
        'Payment Method',
    ];

    $mapping = $service->generate('payments', $headers);

    $this->assertSame([
        'Payment Date' => 'paid_at',
        'Customer' => 'customer_id',
        'Invoice Number' => 'invoice_id',
        'Amount' => 'amount',
        'Payment Method' => 'method',
    ], $mapping);
}
public function test_migration_mapping_service_persists_generated_mapping(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
    ]);

    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = $service->create(
        $session,
        'customers',
        [
            'Customer' => 'name',
            'Email' => 'email',
            'Phone' => 'phone',
            'Company' => 'company_name',
        ]
    );

    $this->assertInstanceOf(
        \App\Models\MigrationMapping::class,
        $mapping
    );

    $this->assertSame($session->id, $mapping->migration_session_id);
    $this->assertSame($tenant->id, $mapping->tenant_id);
    $this->assertSame('customers', $mapping->entity_type);

    $this->assertSame([
        'Customer' => 'name',
        'Email' => 'email',
        'Phone' => 'phone',
        'Company' => 'company_name',
    ], $mapping->field_mapping);
}
public function test_migration_mapping_service_updates_existing_entity_mapping(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
    ]);

    $service = app(\App\Services\MigrationMappingService::class);

    $firstMapping = $service->create(
        $session,
        'customers',
        [
            'Customer' => 'name',
            'Email' => 'email',
        ]
    );

    $secondMapping = $service->create(
        $session,
        'customers',
        [
            'Customer' => 'name',
            'Phone' => 'phone',
        ]
    );

    $this->assertSame($firstMapping->id, $secondMapping->id);

    $this->assertSame([
        'Customer' => 'name',
        'Phone' => 'phone',
    ], $secondMapping->field_mapping);

    $this->assertDatabaseCount('migration_mappings', 1);
}
public function test_migration_mapping_service_rejects_customer_mapping_without_name(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = [
        'Email' => 'email',
        'Phone' => 'phone',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Customer mapping must include a name field.'
    );

    $service->validate('customers', $mapping);
}
public function test_migration_mapping_service_rejects_supplier_mapping_without_name(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = [
        'Email' => 'email',
        'Phone' => 'phone',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Supplier mapping must include a name field.'
    );

    $service->validate('suppliers', $mapping);
}
public function test_migration_mapping_service_rejects_catalog_mapping_without_name(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = [
        'SKU' => 'sku',
        'Description' => 'description',
        'Sales Price' => 'selling_price',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Catalog item mapping must include a name field.'
    );

    $service->validate('catalog_items', $mapping);
}
public function test_migration_mapping_service_rejects_invoice_mapping_without_invoice_number(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = [
        'Customer' => 'customer_id',
        'Invoice Date' => 'issued_at',
        'Due Date' => 'due_at',
        'Amount' => 'total',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Invoice mapping must include an invoice number field.'
    );

    $service->validate('invoices', $mapping);
}
public function test_migration_mapping_service_rejects_expense_mapping_without_amount(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = [
        'Date' => 'expense_date',
        'Payee' => 'description',
        'Category' => 'category_id',
        'Payment Method' => 'payment_method',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Expense mapping must include an amount field.'
    );

    $service->validate('expenses', $mapping);
}
public function test_migration_mapping_service_rejects_payment_mapping_without_amount(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = [
        'Payment Date' => 'paid_at',
        'Customer' => 'customer_id',
        'Invoice Number' => 'invoice_id',
        'Payment Method' => 'method',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Payment mapping must include an amount field.'
    );

    $service->validate('payments', $mapping);
}
public function test_migration_mapping_service_rejects_unknown_customer_target_field(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = [
        'Customer' => 'name',
        'Email' => 'email',
        'Unknown Column' => 'does_not_exist',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Invalid target field "does_not_exist" for customers.'
    );

    $service->validate('customers', $mapping);
}
#[\PHPUnit\Framework\Attributes\DataProvider('invalidMigrationMappingProvider')]
public function test_migration_mapping_service_rejects_invalid_target_fields(
    string $entityType,
    array $mapping,
    string $expectedMessage
): void {
    $service = app(\App\Services\MigrationMappingService::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($expectedMessage);

    $service->validate($entityType, $mapping);
}

public static function invalidMigrationMappingProvider(): array
{
    return [
        'suppliers' => [
            'suppliers',
            ['Vendor' => 'name', 'Bad Field' => 'invalid_field'],
            'Invalid target field "invalid_field" for suppliers.',
        ],

        'catalog items' => [
            'catalog_items',
            ['Product/Service Name' => 'name', 'Bad Field' => 'invalid_field'],
            'Invalid target field "invalid_field" for catalog_items.',
        ],

        'invoices' => [
            'invoices',
            ['Invoice Number' => 'invoice_number', 'Bad Field' => 'invalid_field'],
            'Invalid target field "invalid_field" for invoices.',
        ],

        'expenses' => [
            'expenses',
            ['Amount' => 'amount', 'Bad Field' => 'invalid_field'],
            'Invalid target field "invalid_field" for expenses.',
        ],

        'payments' => [
            'payments',
            ['Amount' => 'amount', 'Bad Field' => 'invalid_field'],
            'Invalid target field "invalid_field" for payments.',
        ],
    ];
}
public function test_migration_mapping_service_allows_unmapped_optional_customer_columns(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = [
        'Customer' => 'name',
        'Email' => 'email',
        'Phone' => null,
        'Company' => null,
    ];

    $service->validate('customers', $mapping);

    $this->assertTrue(true);
}
public function test_migration_mapping_service_rejects_customer_name_mapped_to_null(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = [
        'Customer' => null,
        'Email' => 'email',
        'Phone' => 'phone',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Customer mapping must include a name field.'
    );

    $service->validate('customers', $mapping);
}
#[\PHPUnit\Framework\Attributes\DataProvider('nullRequiredMigrationMappingProvider')]
public function test_migration_mapping_service_rejects_null_required_fields(
    string $entityType,
    array $mapping,
    string $expectedMessage
): void {
    $service = app(\App\Services\MigrationMappingService::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($expectedMessage);

    $service->validate($entityType, $mapping);
}

public static function nullRequiredMigrationMappingProvider(): array
{
    return [
        'suppliers name' => [
            'suppliers',
            ['Vendor' => null, 'Email' => 'email'],
            'Supplier mapping must include a name field.',
        ],

        'catalog item name' => [
            'catalog_items',
            ['Product/Service Name' => null, 'SKU' => 'sku'],
            'Catalog item mapping must include a name field.',
        ],

        'invoice number' => [
            'invoices',
            ['Invoice Number' => null, 'Customer' => 'customer_id'],
            'Invoice mapping must include an invoice number field.',
        ],

        'expense amount' => [
            'expenses',
            ['Date' => 'expense_date', 'Amount' => null],
            'Expense mapping must include an amount field.',
        ],

        'payment amount' => [
            'payments',
            ['Payment Date' => 'paid_at', 'Amount' => null],
            'Payment mapping must include an amount field.',
        ],
    ];
}
public function test_migration_mapping_service_transforms_customer_row(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = [
        'Customer' => 'name',
        'Email' => 'email',
        'Phone' => 'phone',
        'Company' => 'company_name',
    ];

    $row = [
        'Customer' => 'ABC Ltd',
        'Email' => 'abc@example.com',
        'Phone' => '0244000000',
        'Company' => 'ABC Ltd',
    ];

    $result = $service->transformRow('customers', $row, $mapping);

    $this->assertSame([
        'name' => 'ABC Ltd',
        'email' => 'abc@example.com',
        'phone' => '0244000000',
        'company_name' => 'ABC Ltd',
    ], $result);
}
public function test_migration_mapping_service_ignores_unmapped_customer_columns(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = [
        'Customer' => 'name',
        'Email' => 'email',
        'Phone' => null,
        'Company' => null,
    ];

    $row = [
        'Customer' => 'ABC Ltd',
        'Email' => 'abc@example.com',
        'Phone' => '0244000000',
        'Company' => 'ABC Ltd',
    ];

    $result = $service->transformRow('customers', $row, $mapping);

    $this->assertSame([
        'name' => 'ABC Ltd',
        'email' => 'abc@example.com',
    ], $result);
}
public function test_migration_mapping_service_transforms_supplier_row(): void
{
    $service = app(\App\Services\MigrationMappingService::class);

    $mapping = [
        'Vendor' => 'name',
        'Email' => 'email',
        'Phone' => 'phone',
        'Company' => 'company_name',
    ];

    $row = [
        'Vendor' => 'Ghana Supplies Ltd',
        'Email' => 'sales@ghanasupplies.com',
        'Phone' => '0244112233',
        'Company' => 'Ghana Supplies Ltd',
    ];

    $result = $service->transformRow('suppliers', $row, $mapping);

    $this->assertSame([
        'name' => 'Ghana Supplies Ltd',
        'email' => 'sales@ghanasupplies.com',
        'phone' => '0244112233',
        'company_name' => 'Ghana Supplies Ltd',
    ], $result);
}
#[\PHPUnit\Framework\Attributes\DataProvider('migrationRowTransformationProvider')]
public function test_migration_mapping_service_transforms_supported_rows(
    string $entityType,
    array $mapping,
    array $row,
    array $expected
): void {
    $service = app(\App\Services\MigrationMappingService::class);

    $result = $service->transformRow($entityType, $row, $mapping);

    $this->assertSame($expected, $result);
}

public static function migrationRowTransformationProvider(): array
{
    return [
        'catalog item' => [
            'catalog_items',
            [
                'Product/Service Name' => 'name',
                'SKU' => 'sku',
                'Description' => 'description',
                'Sales Price' => 'selling_price',
                'Cost' => 'cost_price',
            ],
            [
                'Product/Service Name' => 'Premium Widget',
                'SKU' => 'PW-001',
                'Description' => 'Premium business widget',
                'Sales Price' => '150.00',
                'Cost' => '90.00',
            ],
            [
                'name' => 'Premium Widget',
                'sku' => 'PW-001',
                'description' => 'Premium business widget',
                'selling_price' => '150.00',
                'cost_price' => '90.00',
            ],
        ],

       'invoice' => [
            'invoices',
            [
                'Invoice Number' => 'invoice_number',
                'Customer' => 'customer_id',
                'Invoice Date' => 'issued_at',
                'Due Date' => 'due_at',
                'Amount' => 'total',
            ],
            [
                'Invoice Number' => 'INV-1001',
                'Customer' => 'ABC Ltd',
                'Invoice Date' => '2026-09-24',
                'Due Date' => '2026-10-24',
                'Amount' => '1250.00',
            ],
            [
                'invoice_number' => 'INV-1001',
                'customer_id' => 'ABC Ltd',
                'issued_at' => '2026-09-24',
                'due_at' => '2026-10-24',
                'total' => '1250.00',
            ],
        ],

       'expense' => [
            'expenses',
            [
                'Date' => 'expense_date',
                'Payee' => 'description',
                'Category' => 'category_id',
                'Amount' => 'amount',
                'Payment Method' => 'payment_method',
            ],
            [
                'Date' => '2026-09-24',
                'Payee' => 'Office Supplies',
                'Category' => 'Office',
                'Amount' => '350.00',
                'Payment Method' => 'cash',
            ],
            [
                'expense_date' => '2026-09-24',
                'description' => 'Office Supplies',
                'category_id' => 'Office',
                'amount' => '350.00',
                'payment_method' => 'cash',
            ],
        ],

       'payment' => [
            'payments',
            [
                'Payment Date' => 'paid_at',
                'Customer' => 'customer_id',
                'Invoice Number' => 'invoice_id',
                'Amount' => 'amount',
                'Payment Method' => 'method',
            ],
            [
                'Payment Date' => '2026-09-24',
                'Customer' => 'ABC Ltd',
                'Invoice Number' => 'INV-1001',
                'Amount' => '500.00',
                'Payment Method' => 'mobile_money',
            ],
            [
                'paid_at' => '2026-09-24',
                'customer_id' => 'ABC Ltd',
                'invoice_id' => 'INV-1001',
                'amount' => '500.00',
                'method' => 'mobile_money',
            ],
        ],
    ];
}
public function test_migration_validation_rejects_invalid_expense_amount(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'expense_date' => '2026-09-24',
        'description' => 'Office Supplies',
        'category_id' => 'Office',
        'amount' => 'not-a-number',
        'payment_method' => 'cash',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Expense amount must be a valid number.'
    );

    $service->validateRow('expenses', $row);
}
public function test_migration_validation_rejects_invalid_invoice_total(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'invoice_number' => 'INV-1001',
        'customer_id' => 'ABC Ltd',
        'issued_at' => '2026-09-24',
        'due_at' => '2026-10-24',
        'total' => 'not-a-number',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Invoice total must be a valid number.'
    );

    $service->validateRow('invoices', $row);
}
public function test_migration_validation_rejects_invalid_catalog_item_selling_price(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'name' => 'Premium Widget',
        'sku' => 'PW-001',
        'description' => 'Premium business widget',
        'selling_price' => 'not-a-number',
        'cost_price' => '90.00',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Catalog item selling price must be a valid number.'
    );

    $service->validateRow('catalog_items', $row);
}
public function test_migration_validation_rejects_invalid_catalog_item_cost_price(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'name' => 'Premium Widget',
        'sku' => 'PW-001',
        'description' => 'Premium business widget',
        'selling_price' => '150.00',
        'cost_price' => 'not-a-number',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Catalog item cost price must be a valid number.'
    );

    $service->validateRow('catalog_items', $row);
}
public function test_migration_validation_rejects_invalid_payment_amount(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'paid_at' => '2026-09-24',
        'customer_id' => 'ABC Ltd',
        'invoice_id' => 'INV-1001',
        'amount' => 'not-a-number',
        'method' => 'mobile_money',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Payment amount must be a valid number.'
    );

    $service->validateRow('payments', $row);
}
public function test_migration_validation_rejects_invalid_invoice_date(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'invoice_number' => 'INV-1001',
        'customer_id' => 'ABC Ltd',
        'issued_at' => 'not-a-date',
        'due_at' => '2026-10-24',
        'total' => '1250.00',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Invoice issued date must be a valid date.'
    );

    $service->validateRow('invoices', $row);
}
public function test_migration_validation_rejects_invalid_expense_date(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'expense_date' => 'not-a-date',
        'description' => 'Office Supplies',
        'category_id' => 'Office',
        'amount' => '350.00',
        'payment_method' => 'cash',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Expense date must be a valid date.'
    );

    $service->validateRow('expenses', $row);
}
public function test_migration_validation_rejects_invalid_payment_date(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'paid_at' => 'not-a-date',
        'customer_id' => 'ABC Ltd',
        'invoice_id' => 'INV-1001',
        'amount' => '500.00',
        'method' => 'mobile_money',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Payment date must be a valid date.'
    );

    $service->validateRow('payments', $row);
}
public function test_migration_validation_rejects_invalid_invoice_due_date(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'invoice_number' => 'INV-1001',
        'customer_id' => 'ABC Ltd',
        'issued_at' => '2026-09-24',
        'due_at' => 'not-a-date',
        'total' => '1250.00',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Invoice due date must be a valid date.'
    );

    $service->validateRow('invoices', $row);
}
public function test_migration_validation_rejects_missing_catalog_item_selling_price(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'name' => 'Premium Widget',
        'sku' => 'PW-001',
        'description' => 'Premium business widget',
        'selling_price' => null,
        'cost_price' => '90.00',
    ];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
        'Catalog item selling price must be a valid number.'
    );

    $service->validateRow('catalog_items', $row);
}
public function test_migration_validation_allows_invoice_without_due_date(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'invoice_number' => 'INV-1001',
        'customer_id' => 'ABC Ltd',
        'issued_at' => '2026-09-24',
        'total' => '1250.00',
    ];

    $service->validateRow('invoices', $row);

    $this->assertTrue(true);
}
public function test_migration_validation_collects_multiple_invoice_errors(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'invoice_number' => null,
        'customer_id' => 'ABC Ltd',
        'issued_at' => 'not-a-date',
        'due_at' => '2026-10-24',
        'total' => 'not-a-number',
    ];

    $errors = $service->validateRowWithErrors('invoices', $row);

    $this->assertSame([
        'Invoice number is required.',
        'Invoice total must be a valid number.',
        'Invoice issued date must be a valid date.',
    ], $errors);
}
public function test_migration_validation_collects_multiple_expense_errors(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $row = [
        'expense_date' => 'not-a-date',
        'description' => 'Office Supplies',
        'category_id' => 'Office',
        'amount' => 'not-a-number',
        'payment_method' => 'cash',
    ];

    $errors = $service->validateRowWithErrors('expenses', $row);

    $this->assertSame([
        'Expense amount must be a valid number.',
        'Expense date must be a valid date.',
    ], $errors);
}
public function test_migration_validation_reports_invalid_rows_with_row_numbers(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $rows = [
        [
            'invoice_number' => 'INV-1001',
            'issued_at' => '2026-09-24',
            'total' => '1250.00',
        ],
        [
            'invoice_number' => null,
            'issued_at' => 'not-a-date',
            'total' => 'not-a-number',
        ],
        [
            'invoice_number' => 'INV-1003',
            'issued_at' => '2026-09-24',
            'total' => '500.00',
        ],
    ];

    $result = $service->validateRows('invoices', $rows);

    $this->assertSame(3, $result['total_rows']);
    $this->assertSame(2, $result['valid_rows']);
    $this->assertSame(1, $result['invalid_rows']);

    $this->assertCount(1, $result['errors']);
    $this->assertSame(2, $result['errors'][0]['row']);
    $this->assertSame([
        'Invoice number is required.',
        'Invoice total must be a valid number.',
        'Invoice issued date must be a valid date.',
    ], $result['errors'][0]['messages']);
}
public function test_migration_validation_accepts_all_valid_rows(): void
{
    $service = app(\App\Services\MigrationValidationService::class);

    $rows = [
        [
            'invoice_number' => 'INV-1001',
            'issued_at' => '2026-09-24',
            'total' => '1250.00',
        ],
        [
            'invoice_number' => 'INV-1002',
            'issued_at' => '2026-09-25',
            'total' => '500.00',
        ],
    ];

    $result = $service->validateRows('invoices', $rows);

    $this->assertSame(2, $result['total_rows']);
    $this->assertSame(2, $result['valid_rows']);
    $this->assertSame(0, $result['invalid_rows']);
    $this->assertSame([], $result['errors']);
}
public function test_migration_session_can_validate_mapped_csv_rows(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = app(\App\Services\MigrationSessionService::class)
        ->create($tenant->id, $user->id);

    $csv = implode("\n", [
        'Customer,Email,Phone',
        'ABC Ltd,abc@example.com,0244000000',
        'XYZ Ltd,xyz@example.com,0244112233',
    ]);

    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent(
        'customers.csv',
        $csv
    );

    $service = app(\App\Services\MigrationSessionService::class);

    $session = $service->attachFile($session, $file);

    $mapping = app(\App\Services\MigrationMappingService::class)
        ->generate(
            'customers',
            ['Customer', 'Email', 'Phone']
        );

    $result = $service->validateMappedRows(
        $session,
        'customers',
        $mapping
    );

    $this->assertSame(2, $result['total_rows']);
    $this->assertSame(2, $result['valid_rows']);
    $this->assertSame(0, $result['invalid_rows']);
    $this->assertSame([], $result['errors']);
}
public function test_migration_session_persists_validation_result(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'customers.csv',
        "Customer,Email,Phone\n"
        . "John Doe,john@example.com,0244000000\n"
        . "Jane Doe,invalid-email,0244111111\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $mapping = [
        'Customer' => 'name',
        'Email' => 'email',
        'Phone' => 'phone',
    ];

    $result = app(MigrationSessionService::class)
        ->validateMappedRows(
            $session->fresh(),
            'customers',
            $mapping
        );

    $this->assertSame(2, $result['total_rows']);
    $this->assertSame(1, $result['valid_rows']);
    $this->assertSame(1, $result['invalid_rows']);

    $this->assertDatabaseHas('migration_validation_results', [
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 2,
        'valid_rows' => 1,
        'invalid_rows' => 1,
    ]);

    $validationResult = MigrationValidationResult::query()
        ->where('migration_session_id', $session->id)
        ->where('entity_type', 'customers')
        ->firstOrFail();

    $this->assertCount(1, $validationResult->errors);
    $this->assertSame(2, $validationResult->errors[0]['row']);
}
public function test_migration_validation_result_is_tenant_scoped(): void
{
    $tenantOne = Tenant::factory()->create();
    $tenantTwo = Tenant::factory()->create();

    $userOne = User::factory()->create([
        'tenant_id' => $tenantOne->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenantOne->id,
        'created_by' => $userOne->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
    ]);

    MigrationValidationResult::create([
        'tenant_id' => $tenantOne->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 10,
        'valid_rows' => 8,
        'invalid_rows' => 2,
        'errors' => [
            [
                'row' => 3,
                'messages' => ['Customer name is required.'],
            ],
        ],
    ]);

    $this->assertDatabaseHas('migration_validation_results', [
        'tenant_id' => $tenantOne->id,
        'migration_session_id' => $session->id,
    ]);

    $this->assertDatabaseMissing('migration_validation_results', [
        'tenant_id' => $tenantTwo->id,
        'migration_session_id' => $session->id,
    ]);

    $this->assertSame(
        0,
        MigrationValidationResult::query()
            ->where('tenant_id', $tenantTwo->id)
            ->where('migration_session_id', $session->id)
            ->count()
    );
}
public function test_migration_session_can_create_import_batch_after_validation(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
    ]);

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 10,
        'valid_rows' => 10,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    $batch = MigrationImportBatch::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'created_by' => $user->id,
        'entity_type' => 'customers',
        'status' => 'pending',
        'total_rows' => 10,
        'successful_rows' => 0,
        'failed_rows' => 0,
        'errors' => [],
    ]);

    $this->assertDatabaseHas('migration_import_batches', [
        'id' => $batch->id,
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'created_by' => $user->id,
        'entity_type' => 'customers',
        'status' => 'pending',
        'total_rows' => 10,
        'successful_rows' => 0,
        'failed_rows' => 0,
    ]);

    $this->assertSame($tenant->id, $batch->tenant_id);
    $this->assertSame($session->id, $batch->migration_session_id);
    $this->assertSame($user->id, $batch->created_by);
    $this->assertSame([], $batch->errors);
}
public function test_import_batch_requires_successful_validation(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'file_path' => 'migration-imports/customers.csv',
    ]);

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 10,
        'valid_rows' => 8,
        'invalid_rows' => 2,
        'errors' => [
            [
                'row' => 3,
                'messages' => ['Customer name is required.'],
            ],
        ],
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
        'Migration cannot be imported while validation errors exist.'
    );

    app(MigrationSessionService::class)->createImportBatch(
        $session,
        $user->id,
        'customers'
    );

    $this->assertDatabaseCount(
        'migration_import_batches',
        0
    );
}
public function test_import_batch_cannot_be_created_twice_for_same_session_and_entity(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'file_path' => 'migration-imports/customers.csv',
    ]);

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 10,
        'valid_rows' => 10,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    app(MigrationSessionService::class)->createImportBatch(
        $session,
        $user->id,
        'customers'
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
        'An import batch already exists for this migration entity.'
    );

    app(MigrationSessionService::class)->createImportBatch(
        $session,
        $user->id,
        'customers'
    );
}
public function test_customer_importer_imports_validated_customers_and_completes_batch(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'customers.csv',
        "Customer,Email,Phone,Company\n"
        . "John Doe,john@example.com,0244000000,John Trading\n"
        . "Jane Doe,jane@example.com,0244111111,Jane Enterprise\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $session->refresh();

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 2,
        'valid_rows' => 2,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    $batch = app(MigrationSessionService::class)->createImportBatch(
        $session,
        $user->id,
        'customers'
    );

    $mapping = [
        'Customer' => 'name',
        'Email' => 'email',
        'Phone' => 'phone',
        'Company' => 'company_name',
    ];

    $result = app(MigrationCustomerImporter::class)->import(
        $session,
        $batch,
        $mapping
    );

    $this->assertSame('completed', $result->status);
    $this->assertSame(2, $result->total_rows);
    $this->assertSame(2, $result->successful_rows);
    $this->assertSame(0, $result->failed_rows);
    $this->assertSame([], $result->errors);

    $this->assertDatabaseHas('customers', [
        'tenant_id' => $tenant->id,
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '0244000000',
        'company_name' => 'John Trading',
    ]);

    $this->assertDatabaseHas('customers', [
        'tenant_id' => $tenant->id,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '0244111111',
        'company_name' => 'Jane Enterprise',
    ]);

    $this->assertSame(
        2,
        Customer::query()
            ->where('tenant_id', $tenant->id)
            ->count()
    );
}
public function test_customer_importer_rolls_back_all_customers_when_import_fails(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'customers.csv',
        "Customer,Email,Phone,Company\n"
        . "John Doe,john@example.com,0244000000,John Trading\n"
        . ",invalid-email,0244111111,Invalid Customer\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $session->refresh();

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 2,
        'valid_rows' => 1,
        'invalid_rows' => 1,
        'errors' => [
            [
                'row' => 2,
                'messages' => [
                    'Customer name is required.',
                ],
            ],
        ],
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
        'Customer migration contains validation errors.'
    );

    $batch = MigrationImportBatch::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'created_by' => $user->id,
        'entity_type' => 'customers',
        'status' => 'pending',
        'total_rows' => 2,
        'successful_rows' => 0,
        'failed_rows' => 0,
        'errors' => [],
    ]);

    app(MigrationCustomerImporter::class)->import(
        $session,
        $batch,
        [
            'Customer' => 'name',
            'Email' => 'email',
            'Phone' => 'phone',
            'Company' => 'company_name',
        ]
    );

    $this->assertDatabaseMissing('customers', [
        'tenant_id' => $tenant->id,
        'name' => 'John Doe',
    ]);
}
public function test_customer_importer_rolls_back_transaction_on_database_failure(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'customers.csv',
        "Customer,Email,Phone,Company\n"
        . "John Doe,john@example.com,0244000000,John Trading\n"
        . "Jane Doe,jane@example.com,0244111111,Jane Enterprise\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $session->refresh();

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 2,
        'valid_rows' => 2,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    $batch = app(MigrationSessionService::class)->createImportBatch(
        $session,
        $user->id,
        'customers'
    );

    $creator = new class extends MigrationCustomerCreator
    {
        private int $calls = 0;

        public function create(int $tenantId, array $data): Customer
        {
            $this->calls++;

            if ($this->calls === 2) {
                throw new RuntimeException(
                    'Simulated database failure.'
                );
            }

            return parent::create($tenantId, $data);
        }
    };

    $importer = new MigrationCustomerImporter($creator);

    $result = $importer->import(
        $session,
        $batch,
        [
            'Customer' => 'name',
            'Email' => 'email',
            'Phone' => 'phone',
            'Company' => 'company_name',
        ]
    );

    $this->assertSame('failed', $result->status);
    $this->assertSame(2, $result->total_rows);
    $this->assertSame(0, $result->successful_rows);
    $this->assertSame(1, $result->failed_rows);

    $this->assertDatabaseMissing('customers', [
        'tenant_id' => $tenant->id,
        'name' => 'John Doe',
    ]);

    $this->assertDatabaseMissing('customers', [
        'tenant_id' => $tenant->id,
        'name' => 'Jane Doe',
    ]);

    $this->assertSame(
        'Simulated database failure.',
        $result->errors[0]['messages'][0]
    );
}
public function test_customer_importer_does_not_duplicate_existing_customer_by_email(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $existingCustomer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Existing Customer',
        'email' => 'john@example.com',
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'customers.csv',
        "Customer,Email,Phone,Company\n"
        . "John Doe,john@example.com,0244000000,John Trading\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $session->refresh();

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 1,
        'valid_rows' => 1,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    $batch = app(MigrationSessionService::class)->createImportBatch(
        $session,
        $user->id,
        'customers'
    );

    $result = app(MigrationCustomerImporter::class)->import(
        $session,
        $batch,
        [
            'Customer' => 'name',
            'Email' => 'email',
            'Phone' => 'phone',
            'Company' => 'company_name',
        ]
    );

    $this->assertSame('completed', $result->status);

    $this->assertSame(
        1,
        Customer::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'john@example.com')
            ->count()
    );

    $this->assertSame(
        $existingCustomer->id,
        Customer::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'john@example.com')
            ->first()
            ->id
    );
}
public function test_supplier_importer_imports_validated_suppliers_and_completes_batch(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'suppliers.csv',
        "Vendor,Email,Phone,Company\n"
        . "ABC Supplies,abc@example.com,0244000000,ABC Trading\n"
        . "XYZ Wholesale,xyz@example.com,0244111111,XYZ Enterprise\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $session->refresh();

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'suppliers',
        'total_rows' => 2,
        'valid_rows' => 2,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    $batch = app(MigrationSessionService::class)->createImportBatch(
        $session,
        $user->id,
        'suppliers'
    );

    $result = app(MigrationSupplierImporter::class)->import(
        $session,
        $batch,
        [
            'Vendor' => 'name',
            'Email' => 'email',
            'Phone' => 'phone',
            'Company' => 'company_name',
        ]
    );

    $this->assertSame('completed', $result->status);
    $this->assertSame(2, $result->total_rows);
    $this->assertSame(2, $result->successful_rows);
    $this->assertSame(0, $result->failed_rows);
    $this->assertSame([], $result->errors);

    $this->assertDatabaseHas('suppliers', [
        'tenant_id' => $tenant->id,
        'name' => 'ABC Supplies',
        'email' => 'abc@example.com',
        'phone' => '0244000000',
        'company_name' => 'ABC Trading',
    ]);

    $this->assertDatabaseHas('suppliers', [
        'tenant_id' => $tenant->id,
        'name' => 'XYZ Wholesale',
        'email' => 'xyz@example.com',
        'phone' => '0244111111',
        'company_name' => 'XYZ Enterprise',
    ]);

    $this->assertSame(
        2,
        Supplier::query()
            ->where('tenant_id', $tenant->id)
            ->count()
    );
}
public function test_catalog_item_importer_imports_products_and_services_and_completes_batch(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'catalog-items.csv',
        "Product/Service Name,SKU,Description,Sales Price,Cost\n"
        . "Laptop,LAP-001,Business laptop,6500,5000\n"
        . "Website Development,SVC-001,Website development service,3500,0\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $session->refresh();

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'catalog_items',
        'total_rows' => 2,
        'valid_rows' => 2,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    $batch = app(MigrationSessionService::class)->createImportBatch(
        $session,
        $user->id,
        'catalog_items'
    );

    $result = app(MigrationCatalogItemImporter::class)->import(
        $session,
        $batch,
        [
            'Product/Service Name' => 'name',
            'SKU' => 'sku',
            'Description' => 'description',
            'Sales Price' => 'selling_price',
            'Cost' => 'cost_price',
        ]
    );

    $this->assertSame('completed', $result->status);
    $this->assertSame(2, $result->total_rows);
    $this->assertSame(2, $result->successful_rows);
    $this->assertSame(0, $result->failed_rows);
    $this->assertSame([], $result->errors);

    $this->assertDatabaseHas('catalog_items', [
        'tenant_id' => $tenant->id,
        'name' => 'Laptop',
        'sku' => 'LAP-001',
        'description' => 'Business laptop',
        'selling_price' => 6500,
        'cost_price' => 5000,
    ]);

    $this->assertDatabaseHas('catalog_items', [
        'tenant_id' => $tenant->id,
        'name' => 'Website Development',
        'sku' => 'SVC-001',
        'description' => 'Website development service',
        'selling_price' => 3500,
        'cost_price' => 0,
    ]);

    $this->assertSame(
        2,
        CatalogItem::query()
            ->where('tenant_id', $tenant->id)
            ->count()
    );
}
public function test_catalog_item_creator_does_not_duplicate_existing_item_by_sku(): void
{
    $tenant = Tenant::factory()->create();

    $existing = CatalogItem::create([
        'tenant_id' => $tenant->id,
        'type' => CatalogItemType::PRODUCT,
        'name' => 'Existing Laptop',
        'sku' => 'LAP-001',
        'description' => 'Original item',
        'unit' => 'pcs',
        'cost_price' => 4000,
        'selling_price' => 5500,
        'tax_rate' => 0,
        'track_inventory' => true,
        'is_active' => true,
    ]);

    $result = app(MigrationCatalogItemCreator::class)->create(
        $tenant->id,
        [
            'type' => CatalogItemType::PRODUCT,
            'name' => 'Imported Laptop',
            'sku' => 'LAP-001',
            'description' => 'Imported description',
            'cost_price' => 5000,
            'selling_price' => 6500,
        ]
    );

    $this->assertSame($existing->id, $result->id);

    $this->assertSame(
        1,
        CatalogItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('sku', 'LAP-001')
            ->count()
    );

    $this->assertSame('Existing Laptop', $result->name);
    $this->assertSame('Original item', $result->description);
}
public function test_migration_invoice_creator_creates_invoice_and_prevents_duplicate_invoice_number(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $creator = app(MigrationInvoiceCreator::class);

    $invoice = $creator->create(
        $tenant->id,
        [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'invoice_number' => 'QB-INV-001',
            'status' => 'issued',
            'payment_status' => 'unpaid',
            'subtotal' => 1000,
            'discount' => 0,
            'tax' => 150,
            'total' => 1150,
            'issued_at' => '2026-09-24 10:00:00',
            'due_at' => '2026-10-24 10:00:00',
        ]
    );

    $duplicate = $creator->create(
        $tenant->id,
        [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'invoice_number' => 'QB-INV-001',
            'total' => 9999,
        ]
    );

    $this->assertSame($invoice->id, $duplicate->id);

    $this->assertSame(
        1,
        Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('invoice_number', 'QB-INV-001')
            ->count()
    );

    $this->assertSame('QB-INV-001', $invoice->invoice_number);
    $this->assertSame('1150.00', $invoice->total);
}
public function test_migration_customer_resolver_resolves_customer_within_tenant_only(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'ABC Trading',
    ]);

    Customer::factory()->create([
        'tenant_id' => $otherTenant->id,
        'name' => 'ABC Trading',
    ]);

    $resolver = app(MigrationCustomerResolver::class);

    $result = $resolver->resolve(
        $tenant->id,
        'abc trading'
    );

    $this->assertSame($customer->id, $result->id);
    $this->assertSame($tenant->id, $result->tenant_id);
}
public function test_invoice_importer_resolves_customers_and_completes_batch(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'ABC Trading',
        'email' => 'abc@example.com',
    ]);

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'XYZ Enterprise',
        'email' => 'xyz@example.com',
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'invoices.csv',
        "Invoice Number,Customer,Invoice Date,Due Date,Amount\n"
        . "INV-001,ABC Trading,2026-09-01,2026-09-30,1500\n"
        . "INV-002,XYZ Enterprise,2026-09-02,2026-10-01,2500\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $session->refresh();

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'invoices',
        'total_rows' => 2,
        'valid_rows' => 2,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    $batch = app(MigrationSessionService::class)->createImportBatch(
        $session,
        $user->id,
        'invoices'
    );

    $result = app(MigrationInvoiceImporter::class)->import(
        $session,
        $batch,
        [
            'Invoice Number' => 'invoice_number',
            'Customer' => 'customer_id',
            'Invoice Date' => 'issued_at',
            'Due Date' => 'due_at',
            'Amount' => 'total',
        ],
        $branch->id
    );

    $this->assertSame('completed', $result->status);
    $this->assertSame(2, $result->total_rows);
    $this->assertSame(2, $result->successful_rows);
    $this->assertSame(0, $result->failed_rows);
    $this->assertSame([], $result->errors);

    $this->assertDatabaseHas('invoices', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'invoice_number' => 'INV-001',
        'total' => 1500,
    ]);

    $this->assertDatabaseHas('invoices', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'invoice_number' => 'INV-002',
        'total' => 2500,
    ]);

    $this->assertSame(
        2,
        Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->count()
    );
}
public function test_migration_expense_creator_creates_expense(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $expense = app(MigrationExpenseCreator::class)->create(
        $tenant->id,
        [
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'amount' => 750,
            'expense_date' => '2026-09-24 00:00:00',
            'description' => 'Office supplies',
            'payment_method' => 'cash',
        ]
    );

    $this->assertDatabaseHas('expenses', [
        'id' => $expense->id,
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'amount' => 750,
        'expense_date' => '2026-09-24 00:00:00',
        'description' => 'Office supplies',
        'payment_method' => 'cash',
    ]);
}
public function test_expense_importer_imports_validated_expenses_and_completes_batch(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $stationery = Category::create([
        'tenant_id' => $tenant->id,
        'name' => 'Stationery',
    ]);

    $internet = Category::create([
        'tenant_id' => $tenant->id,
        'name' => 'Internet',
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'expenses.csv',
        "Date,Payee,Category,Amount,Payment Method\n"
        . "2026-09-01,Office Supplies,Stationery,750,Cash\n"
        . "2026-09-02,MTN Ghana,Internet,300,Mobile Money\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $session->refresh();

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'expenses',
        'total_rows' => 2,
        'valid_rows' => 2,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    $batch = app(MigrationSessionService::class)->createImportBatch(
        $session,
        $user->id,
        'expenses'
    );

    $result = app(MigrationExpenseImporter::class)->import(
        $session,
        $batch,
        [
            'Date' => 'expense_date',
            'Payee' => 'description',
            'Category' => 'category_id',
            'Amount' => 'amount',
            'Payment Method' => 'payment_method',
        ],
        $branch->id
    );

   $this->assertSame(
        'completed',
        $result->status,
        json_encode($result->errors, JSON_PRETTY_PRINT)
    );
    $this->assertSame(2, $result->total_rows);
    $this->assertSame(2, $result->successful_rows);
    $this->assertSame(0, $result->failed_rows);
    $this->assertSame([], $result->errors);

    $this->assertDatabaseHas('expenses', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'amount' => 750,
        'description' => 'Office Supplies',
       'payment_method' => 'cash',
    ]);

    $this->assertDatabaseHas('expenses', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'amount' => 300,
        'description' => 'MTN Ghana',
        'payment_method' => 'mobile_money',
    ]);

    $this->assertSame(
        2,
        Expense::query()
            ->where('tenant_id', $tenant->id)
            ->count()
    );
}
public function test_migration_category_resolver_resolves_category_within_tenant_only(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $category = Category::create([
        'tenant_id' => $tenant->id,
        'name' => 'Stationery',
    ]);

    Category::create([
        'tenant_id' => $otherTenant->id,
        'name' => 'Stationery',
    ]);

    $resolver = app(MigrationCategoryResolver::class);

    $result = $resolver->resolve(
        $tenant->id,
        'stationery'
    );

    $this->assertSame($category->id, $result->id);
    $this->assertSame($tenant->id, $result->tenant_id);
}
public function test_migration_invoice_payment_creator_creates_payment(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
    ]);

    $payment = app(MigrationInvoicePaymentCreator::class)->create(
        $tenant->id,
        [
            'invoice_id' => $invoice->id,
            'recorded_by' => $user->id,
            'amount' => 500,
            'method' => InvoicePaymentMethod::CASH->value,
            'reference' => 'QB-PAY-001',
            'notes' => 'QuickBooks historical payment',
            'paid_at' => '2026-09-10 10:00:00',
        ]
    );

    $this->assertDatabaseHas('invoice_payments', [
        'id' => $payment->id,
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'recorded_by' => $user->id,
        'amount' => 500,
        'method' => 'cash',
        'reference' => 'QB-PAY-001',
    ]);

    $this->assertSame(
        '2026-09-10 10:00:00',
        $payment->paid_at->format('Y-m-d H:i:s')
    );
}
public function test_migration_invoice_resolver_resolves_invoice_within_tenant_only(): void
{
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherUser = User::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherBranch = Branch::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherCustomer = Customer::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'invoice_number' => 'INV-1001',
    ]);

    Invoice::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'customer_id' => $otherCustomer->id,
        'created_by' => $otherUser->id,
        'invoice_number' => 'INV-1001',
    ]);

    $resolver = app(MigrationInvoiceResolver::class);

    $invoice = $resolver->resolve(
        $tenant->id,
        'INV-1001'
    );

    $this->assertSame($tenant->id, $invoice->tenant_id);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage(
        "Invoice 'INV-9999' could not be found for this tenant."
    );

    $resolver->resolve(
        $tenant->id,
        'INV-9999'
    );
}
public function test_invoice_payment_importer_resolves_invoices_and_completes_batch(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Acme Ltd',
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'invoice_number' => 'INV-1001',
        'total' => 1000,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'payments.csv',
        "Payment Date,Customer,Invoice Number,Amount,Payment Method\n"
        . "2026-09-10,Acme Ltd,INV-1001,500,Cash\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $session->refresh();

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'payments',
        'total_rows' => 1,
        'valid_rows' => 1,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    $batch = app(MigrationSessionService::class)->createImportBatch(
        $session,
        $user->id,
        'payments'
    );

    $result = app(MigrationInvoicePaymentImporter::class)->import(
        $session,
        $batch,
        [
            'Payment Date' => 'paid_at',
            'Customer' => 'customer_id',
            'Invoice Number' => 'invoice_id',
            'Amount' => 'amount',
            'Payment Method' => 'method',
        ]
    );

    $this->assertSame(
        'completed',
        $result->status,
        json_encode($result->errors, JSON_PRETTY_PRINT)
    );

    $this->assertSame(1, $result->total_rows);
    $this->assertSame(1, $result->successful_rows);
    $this->assertSame(0, $result->failed_rows);
    $this->assertSame([], $result->errors);

    $this->assertDatabaseHas('invoice_payments', [
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'recorded_by' => $user->id,
        'amount' => 500,
        'method' => 'cash',
    ]);
}
public function test_authenticated_user_can_create_migration_session(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/migration-sessions', [
            'source' => 'quickbooks',
        ]);

    $response
        ->assertCreated()
        ->assertJson([
            'success' => true,
            'message' => 'Migration session created successfully.',
            'data' => [
                'source' => 'quickbooks',
                'status' => 'pending',
            ],
        ]);

    $this->assertDatabaseHas('migration_sessions', [
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => 'quickbooks',
        'status' => 'pending',
    ]);
}
public function test_authenticated_user_can_upload_quickbooks_csv_to_migration_session(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'customers.csv',
        "Customer,Email,Phone\n"
        . "Acme Ltd,acme@example.com,0244000000\n"
    );

    $response = $this
        ->actingAs($user)
        ->post(
            "/api/migration-sessions/{$session->id}/upload",
            ['file' => $file]
        );

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Migration file uploaded successfully.',
            'data' => [
                'id' => $session->id,
                'source' => 'quickbooks',
                'status' => 'uploaded',
                'original_filename' => 'customers.csv',
                'mime_type' => 'text/csv',
            ],
        ]);

    $session->refresh();

    $this->assertSame(
        MigrationSessionStatus::UPLOADED,
        $session->status
    );

    Storage::disk('local')->assertExists($session->file_path);
}
public function test_authenticated_user_can_analyze_uploaded_quickbooks_file(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'customers.csv',
        "Customer,Email,Phone\n"
        . "Acme Ltd,acme@example.com,0244000000\n"
        . "Beta Ltd,beta@example.com,0244111111\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $session->refresh();

    $response = $this
        ->actingAs($user)
        ->postJson(
            "/api/migration-sessions/{$session->id}/analyze"
        );

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Migration file analyzed successfully.',
            'data' => [
                'migration_session_id' => $session->id,
                'entity_type' => 'customers',
                'row_count' => 2,
                'headers' => [
                    'Customer',
                    'Email',
                    'Phone',
                ],
            ],
        ]);

    $this->assertDatabaseHas('migration_analysis_results', [
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'row_count' => 2,
    ]);

    $session->refresh();

    $this->assertSame(
        MigrationSessionStatus::ANALYZING,
        $session->status
    );
}
public function test_authenticated_user_can_save_migration_mapping(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
    ]);

    $mapping = [
        'Customer' => 'name',
        'Email' => 'email',
        'Phone' => 'phone',
        'Company' => 'company_name',
    ];

    $response = $this
        ->actingAs($user)
        ->postJson(
            "/api/migration-sessions/{$session->id}/mapping",
            [
                'entity_type' => 'customers',
                'field_mapping' => $mapping,
            ]
        );

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Migration mapping saved successfully.',
            'data' => [
                'migration_session_id' => $session->id,
                'entity_type' => 'customers',
                'field_mapping' => $mapping,
            ],
        ]);

    $this->assertDatabaseHas('migration_mappings', [
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
    ]);
}
public function test_authenticated_user_can_validate_migration_data(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'customers.csv',
        "Customer,Email,Phone\n"
        . "Acme Ltd,acme@example.com,0244000000\n"
        . "Beta Ltd,beta@example.com,0244111111\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $session->refresh();

    $response = $this
        ->actingAs($user)
        ->postJson(
            "/api/migration-sessions/{$session->id}/validate",
            [
                'entity_type' => 'customers',
                'field_mapping' => [
                    'Customer' => 'name',
                    'Email' => 'email',
                    'Phone' => 'phone',
                ],
            ]
        );

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Migration data validated successfully.',
            'data' => [
                'total_rows' => 2,
                'valid_rows' => 2,
                'invalid_rows' => 0,
                'errors' => [],
            ],
        ]);

    $this->assertDatabaseHas('migration_validation_results', [
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 2,
        'valid_rows' => 2,
        'invalid_rows' => 0,
    ]);
}
public function test_authenticated_user_can_create_migration_import_batch(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::PENDING,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'customers.csv',
        "Customer,Email\n"
        . "Acme Ltd,acme@example.com\n"
        . "Beta Ltd,beta@example.com\n"
    );

    app(MigrationSessionService::class)->attachFile(
        $session,
        $file
    );

    $session->refresh();

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 2,
        'valid_rows' => 2,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson(
            "/api/migration-sessions/{$session->id}/batches",
            [
                'entity_type' => 'customers',
            ]
        );

    $response
        ->assertCreated()
        ->assertJson([
            'success' => true,
            'message' => 'Migration import batch created successfully.',
            'data' => [
                'migration_session_id' => $session->id,
                'entity_type' => 'customers',
                'status' => 'pending',
                'total_rows' => 2,
                'successful_rows' => 0,
                'failed_rows' => 0,
                'errors' => [],
            ],
        ]);

    $this->assertDatabaseHas('migration_import_batches', [
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'created_by' => $user->id,
        'entity_type' => 'customers',
        'status' => 'pending',
        'total_rows' => 2,
    ]);
}
public function test_migration_import_service_routes_customer_batch_to_customer_importer(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
    ]);

    $batch = MigrationImportBatch::create([
    'tenant_id' => $tenant->id,
    'migration_session_id' => $session->id,
    'created_by' => $user->id,
    'entity_type' => 'customers',
    'status' => 'pending',
]);

    $mapping = [
        'Customer' => 'name',
        'Email' => 'email',
        'Phone' => 'phone',
    ];

    $customerImporter = $this->mock(MigrationCustomerImporter::class);

    $customerImporter
        ->shouldReceive('import')
        ->once()
        ->with($session, $batch, $mapping)
        ->andReturn($batch);

    $service = app(MigrationImportService::class);

    $result = $service->import(
        $session,
        $batch,
        $mapping
    );

    $this->assertSame($batch->id, $result->id);
}
public function test_migration_import_service_requires_branch_for_invoice_import(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
    ]);

    $batch = MigrationImportBatch::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'created_by' => $user->id,
        'entity_type' => 'invoices',
        'status' => 'pending',
    ]);

    $mapping = [
        'Invoice Number' => 'invoice_number',
        'Customer' => 'customer_id',
        'Invoice Date' => 'issued_at',
        'Due Date' => 'due_at',
        'Amount' => 'total',
    ];

    $this->mock(MigrationInvoiceImporter::class);

    $service = app(MigrationImportService::class);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage(
        'A branch ID is required for this migration entity type.'
    );

    $service->import(
        $session,
        $batch,
        $mapping
    );
}
public function test_migration_import_service_routes_invoice_batch_with_branch(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
    ]);

    $batch = MigrationImportBatch::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'created_by' => $user->id,
        'entity_type' => 'invoices',
        'status' => 'pending',
    ]);

    $mapping = [
        'Invoice Number' => 'invoice_number',
        'Customer' => 'customer_id',
        'Invoice Date' => 'issued_at',
        'Due Date' => 'due_at',
        'Amount' => 'total',
    ];

    $invoiceImporter = $this->mock(MigrationInvoiceImporter::class);

    $invoiceImporter
        ->shouldReceive('import')
        ->once()
        ->with($session, $batch, $mapping, $branch->id)
        ->andReturn($batch);

    $service = app(MigrationImportService::class);

    $result = $service->import(
        $session,
        $batch,
        $mapping,
        $branch->id
    );

    $this->assertSame($batch->id, $result->id);
}
public function test_migration_import_service_requires_branch_for_expense_import(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
    ]);

    $batch = MigrationImportBatch::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'created_by' => $user->id,
        'entity_type' => 'expenses',
        'status' => 'pending',
    ]);

    $mapping = [
        'Date' => 'expense_date',
        'Payee' => 'description',
        'Category' => 'category_id',
        'Amount' => 'amount',
        'Payment Method' => 'payment_method',
    ];

    $this->mock(MigrationExpenseImporter::class);

    $service = app(MigrationImportService::class);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage(
        'A branch ID is required for this migration entity type.'
    );

    $service->import(
        $session,
        $batch,
        $mapping
    );
}
public function test_migration_import_service_routes_standard_entity_batches(): void
{
    $cases = [
        [
            'entity_type' => 'suppliers',
            'importer' => MigrationSupplierImporter::class,
        ],
        [
            'entity_type' => 'catalog_items',
            'importer' => MigrationCatalogItemImporter::class,
        ],
        [
            'entity_type' => 'payments',
            'importer' => MigrationInvoicePaymentImporter::class,
        ],
    ];

    foreach ($cases as $case) {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $session = MigrationSession::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'source' => MigrationSource::QUICKBOOKS,
            'status' => MigrationSessionStatus::UPLOADED,
        ]);

        $batch = MigrationImportBatch::create([
            'tenant_id' => $tenant->id,
            'migration_session_id' => $session->id,
            'created_by' => $user->id,
            'entity_type' => $case['entity_type'],
            'status' => 'pending',
        ]);

        $mapping = [
            'Name' => 'name',
            'Email' => 'email',
        ];

        $importer = $this->mock($case['importer']);

        $importer
            ->shouldReceive('import')
            ->once()
            ->with($session, $batch, $mapping)
            ->andReturn($batch);

        $service = app(MigrationImportService::class);

        $result = $service->import(
            $session,
            $batch,
            $mapping
        );

        $this->assertSame($batch->id, $result->id);
    }
}
public function test_authenticated_user_can_run_migration_import_batch(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->actingAs($user);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'original_filename' => 'customers.csv',
        'file_path' => 'migration-imports/customers.csv',
        'file_size' => 100,
        'mime_type' => 'text/csv',
    ]);

    Storage::disk('local')->put(
        'migration-imports/customers.csv',
        "Customer,Email,Phone\nJohn Doe,john@example.com,0244000000"
    );

    $batch = MigrationImportBatch::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'created_by' => $user->id,
        'entity_type' => 'customers',
        'status' => 'pending',
        'total_rows' => 1,
    ]);

    MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 1,
        'valid_rows' => 1,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    $response = $this->postJson(
        "/api/migration-sessions/{$session->id}/batches/{$batch->id}/import",
        [
            'mapping' => [
                'Customer' => 'name',
                'Email' => 'email',
                'Phone' => 'phone',
            ],
        ]
    );

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath(
            'data.status',
            'completed'
        )
        ->assertJsonPath(
            'data.successful_rows',
            1
        )
        ->assertJsonPath(
            'data.failed_rows',
            0
        );

    $this->assertDatabaseHas('customers', [
        'tenant_id' => $tenant->id,
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);
}
public function test_user_cannot_run_migration_import_for_another_tenant(): void
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();

    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $otherUser = User::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $otherTenant->id,
        'created_by' => $otherUser->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
    ]);

    $batch = MigrationImportBatch::create([
        'tenant_id' => $otherTenant->id,
        'migration_session_id' => $session->id,
        'created_by' => $otherUser->id,
        'entity_type' => 'customers',
        'status' => 'pending',
    ]);

    $this->actingAs($user);

    $response = $this->postJson(
        "/api/migration-sessions/{$session->id}/batches/{$batch->id}/import",
        [
            'mapping' => [
                'Customer' => 'name',
                'Email' => 'email',
            ],
        ]
    );

    $response->assertNotFound();

    $this->assertDatabaseMissing('customers', [
        'tenant_id' => $otherTenant->id,
    ]);
}
public function test_migration_import_exception_does_not_expose_internal_details(): void
{
   $tenant = Tenant::factory()->create();

$user = User::factory()->create([
    'tenant_id' => $tenant->id,
]);

    $session = MigrationSession::factory()->create([
        'tenant_id' => $user->tenant_id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
    ]);

   $batch = MigrationImportBatch::create([
    'tenant_id' => $user->tenant_id,
    'migration_session_id' => $session->id,
    'created_by' => $user->id,
    'entity_type' => 'customers',
    'status' => 'pending',
    'total_rows' => 1,
    'successful_rows' => 0,
    'failed_rows' => 0,
]);

    $response = $this->actingAs($user)
        ->postJson(
            "/api/migration-sessions/{$session->id}/batches/{$batch->id}/import",
            [
                'mapping' => [
                    'name' => 'Display Name',
                ],
            ]
        );

    $response
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Migration import failed.',
        ])
        ->assertJsonMissing(['exception'])
        ->assertJsonMissing(['file'])
        ->assertJsonMissing(['trace']);
}
public function test_migration_import_service_rejects_completed_batch(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
    ]);

    $batch = MigrationImportBatch::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'created_by' => $user->id,
        'entity_type' => 'customers',
        'status' => 'completed',
    ]);

    $mapping = [
        'Customer' => 'name',
        'Email' => 'email',
    ];

    $customerImporter = $this->mock(MigrationCustomerImporter::class);

    $customerImporter
        ->shouldNotReceive('import');

    $service = app(MigrationImportService::class);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage(
        'Only pending migration import batches can be imported.'
    );

    $service->import(
        $session,
        $batch,
        $mapping
    );
}
public function test_migration_import_service_rejects_session_and_batch_from_different_tenants(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenantA->id,
        'created_by' => $userA->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
    ]);

    $batch = MigrationImportBatch::create([
        'tenant_id' => $tenantB->id,
        'migration_session_id' => $session->id,
        'created_by' => $userB->id,
        'entity_type' => 'customers',
        'status' => 'pending',
    ]);

    $customerImporter = $this->mock(MigrationCustomerImporter::class);

    $customerImporter
        ->shouldNotReceive('import');

    $service = app(MigrationImportService::class);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage(
        'Migration session and import batch must belong to the same tenant.'
    );

    $service->import(
        $session,
        $batch,
        [
            'Customer' => 'name',
            'Email' => 'email',
        ]
    );
}
public function test_authenticated_user_can_review_validated_migration(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $session = MigrationSession::create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'source' => MigrationSource::QUICKBOOKS,
        'status' => MigrationSessionStatus::UPLOADED,
        'original_filename' => 'customers.csv',
        'file_path' => 'migration-imports/customers.csv',
        'file_size' => 1024,
        'mime_type' => 'text/csv',
    ]);

    \App\Models\MigrationAnalysisResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'row_count' => 2,
        'headers' => ['Customer', 'Email'],
        'sample_rows' => [
            [
                'Customer' => 'John Doe',
                'Email' => 'john@example.com',
            ],
        ],
    ]);

    \App\Models\MigrationMapping::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'field_mapping' => [
            'Customer' => 'name',
            'Email' => 'email',
        ],
    ]);

    \App\Models\MigrationValidationResult::create([
        'tenant_id' => $tenant->id,
        'migration_session_id' => $session->id,
        'entity_type' => 'customers',
        'total_rows' => 2,
        'valid_rows' => 2,
        'invalid_rows' => 0,
        'errors' => [],
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            "/api/migration-sessions/{$session->id}/review?entity_type=customers"
        );

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath(
            'data.session.id',
            $session->id
        )
        ->assertJsonPath(
            'data.analysis.entity_type',
            'customers'
        )
        ->assertJsonPath(
            'data.analysis.row_count',
            2
        )
        ->assertJsonPath(
            'data.mapping.field_mapping.Customer',
            'name'
        )
        ->assertJsonPath(
            'data.validation.total_rows',
            2
        )
        ->assertJsonPath(
            'data.validation.valid_rows',
            2
        )
        ->assertJsonPath(
            'data.validation.invalid_rows',
            0
        )
        ->assertJsonPath(
            'data.import.ready',
            true
        )
        ->assertJsonPath(
            'data.import.batch_exists',
            false
        );
}
}