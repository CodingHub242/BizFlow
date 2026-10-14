<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuickBooksCustomerImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.quickbooks.environment' => 'sandbox']);
    }

    public function test_import_requires_authentication(): void
    {
        $user = $this->userForTenant();
        $connection = $this->connectionFor($user);

        $this->postJson($this->endpoint($connection))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_tenant_cannot_import_from_another_tenants_connection(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $otherUser = $this->userForTenant();
        $connection = $this->connectionFor($otherUser);

        $this->actingAs($user)->postJson($this->endpoint($connection))->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_import_creates_customers_and_maps_quickbooks_source_ids(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakeCustomers($this->customerRecords());

        $response = $this->actingAs($user)->postJson($this->endpoint($connection));

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'connection_id' => $connection->id,
                'entity_type' => 'customers',
                'fetched_count' => 2,
                'imported_count' => 2,
                'already_imported_count' => 0,
                'skipped_count' => 0,
            ],
        ]);

        $customer = Customer::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('name', 'Ada Mensah')
            ->firstOrFail();

        $this->assertSame('ada@example.com', $customer->email);
        $this->assertSame('+233200000001', $customer->phone);
        $this->assertSame('1 Independence Ave, Accra, Greater Accra, Ghana', $customer->address);
        $this->assertSame('active', $customer->status);

        $this->assertDatabaseHas('quickbooks_import_mappings', [
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => 'customers',
            'quickbooks_id' => '101',
            'local_type' => Customer::class,
            'local_id' => $customer->id,
        ]);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/v3/company/9341458483714643/query')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return ($query['query'] ?? null) === 'select * from Customer startposition 1 maxresults 1000';
        });
    }

    public function test_import_is_idempotent_and_preserves_local_customer_edits(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakeCustomers($this->customerRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 2);

        $customer = Customer::query()->where('tenant_id', $user->tenant_id)->where('name', 'Ada Mensah')->firstOrFail();
        $customer->forceFill(['name' => 'Bizflow-edited name'])->save();

        $this->fakeCustomers($this->customerRecords());
        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.already_imported_count', 2);

        $this->assertSame(2, Customer::query()->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame('Bizflow-edited name', $customer->fresh()->name);
        $this->assertSame(2, QuickBooksImportMapping::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('quickbooks_connection_id', $connection->id)
            ->where('entity_type', 'customers')
            ->count());
    }

    public function test_invalid_provider_customer_list_returns_safe_error_without_importing(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakeCustomers(['Customer' => 'not a customer list']);

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('quickbooks_import_mappings', 0);
    }

    public function test_user_without_migration_import_permission_cannot_import_customers(): void
    {
        $user = $this->userForTenant();
        Permission::findOrCreate('migration.import', 'web');
        $connection = $this->connectionFor($user);

        $this->actingAs($user)->postJson($this->endpoint($connection))->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('customers', 0);
    }

    private function userForTenant(): User
    {
        $tenant = Tenant::factory()->create();
        return User::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function grantMigrationImportPermission(User $user): void
    {
        setPermissionsTeamId((int) $user->tenant_id);
        $permission = Permission::findOrCreate('migration.import', 'web');
        $role = Role::findOrCreate('quickbooks-customer-import-test-role', 'web');
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        setPermissionsTeamId((int) $user->tenant_id);
    }

    private function connectionFor(User $user): QuickBooksConnection
    {
        return QuickBooksConnection::query()->create([
            'tenant_id' => $user->tenant_id,
            'connected_by' => $user->id,
            'realm_id' => '9341458483714643',
            'company_name' => 'Sandbox Company US ca89',
            'connection_status' => 'connected',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'connected_at' => now(),
        ]);
    }

    private function endpoint(QuickBooksConnection $connection): string
    {
        return '/api/integrations/quickbooks/connections/'.$connection->id.'/migration-catalog/customers/import';
    }

    /** @return array<int, array<string, mixed>> */
    private function customerRecords(): array
    {
        return [
            [
                'Id' => '101',
                'DisplayName' => 'Ada Mensah',
                'CompanyName' => 'Ada Mensah Trading',
                'PrimaryEmailAddr' => ['Address' => 'ada@example.com'],
                'PrimaryPhone' => ['FreeFormNumber' => '+233200000001'],
                'BillAddr' => [
                    'Line1' => '1 Independence Ave',
                    'City' => 'Accra',
                    'CountrySubDivisionCode' => 'Greater Accra',
                    'Country' => 'Ghana',
                ],
                'Active' => true,
                'Notes' => 'Sandbox customer',
            ],
            [
                'Id' => '102',
                'DisplayName' => 'Kofi Boateng',
                'GivenName' => 'Kofi',
                'FamilyName' => 'Boateng',
                'Active' => false,
            ],
        ];
    }

    private function fakeCustomers(array $rows): void
    {
        // Standard fixtures are a list of Customer records. Keep associative
        // input unchanged for the deliberately malformed-response test.
        $queryResponse = array_is_list($rows) ? ['Customer' => $rows] : $rows;

        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/v3/company/*/query*' => Http::response([
                'QueryResponse' => $queryResponse,
            ], 200),
        ]);
    }
}
