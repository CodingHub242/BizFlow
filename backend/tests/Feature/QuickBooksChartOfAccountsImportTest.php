<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use App\Models\Tenant;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuickBooksChartOfAccountsImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.quickbooks.environment' => 'sandbox',
        ]);
    }

    public function test_import_requires_authentication(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $connection = $this->connectionFor($user);

        $this->postJson($this->endpoint($connection))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_tenant_cannot_import_from_another_tenants_connection(): void
    {
        $owner = $this->userForTenant();
        $this->grantMigrationImportPermission($owner);
        $otherUser = $this->userForTenant();
        $connection = $this->connectionFor($otherUser);

        $this->actingAs($owner)
            ->postJson($this->endpoint($connection))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('chart_of_accounts', 0);
    }

    public function test_import_creates_accounts_and_resolves_hierarchy_by_quickbooks_id(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakeAccounts($this->accountRecords());

        $response = $this->actingAs($user)->postJson($this->endpoint($connection));

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'connection_id' => $connection->id,
                'entity_type' => 'chart_of_accounts',
                'fetched_count' => 2,
                'imported_count' => 2,
                'already_imported_count' => 0,
                'skipped_count' => 0,
                'hierarchy_links' => 1,
            ],
        ]);

        $parent = ChartOfAccount::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('name', 'Accounts Payable (A/P)')
            ->firstOrFail();
        $child = ChartOfAccount::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('name', 'Trade Payables')
            ->firstOrFail();

        $this->assertNull($parent->account_number);
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertTrue($child->is_sub_account);
        $this->assertDatabaseHas('quickbooks_import_mappings', [
            'tenant_id' => $user->tenant_id,
            'quickbooks_connection_id' => $connection->id,
            'entity_type' => 'chart_of_accounts',
            'quickbooks_id' => '33',
            'local_type' => ChartOfAccount::class,
            'local_id' => $parent->id,
        ]);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/v3/company/9341458483714643/query')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return ($query['query'] ?? null) === 'select * from Account startposition 1 maxresults 1000';
        });
    }

    public function test_import_is_idempotent_and_does_not_duplicate_accounts(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakeAccounts($this->accountRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 2);

        $this->fakeAccounts($this->accountRecords());

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.already_imported_count', 2);

        $this->assertSame(2, ChartOfAccount::query()->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame(2, QuickBooksImportMapping::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('quickbooks_connection_id', $connection->id)
            ->where('entity_type', 'chart_of_accounts')
            ->count());
    }

    public function test_missing_or_invalid_provider_list_returns_safe_error_without_importing(): void
    {
        $user = $this->userForTenant();
        $this->grantMigrationImportPermission($user);
        $connection = $this->connectionFor($user);
        $this->fakeAccounts(['Account' => 'not an account list']);

        $this->actingAs($user)->postJson($this->endpoint($connection))
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('chart_of_accounts', 0);
        $this->assertDatabaseCount('quickbooks_import_mappings', 0);
    }


    public function test_user_without_migration_import_permission_cannot_import_accounts(): void
    {
        $user = $this->userForTenant();
        Permission::findOrCreate('migration.import', 'web');
        $connection = $this->connectionFor($user);

        $this->actingAs($user)
            ->postJson($this->endpoint($connection))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('chart_of_accounts', 0);
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
        $role = Role::findOrCreate('quickbooks-migration-test-role', 'web');
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
        return '/api/integrations/quickbooks/connections/'.$connection->id.'/migration-catalog/chart_of_accounts/import';
    }

    /** @return array<int, array<string, mixed>> */
    private function accountRecords(): array
    {
        // Child precedes parent to confirm that the importer uses two passes.
        return [
            [
                'Id' => '84',
                'Name' => 'Trade Payables',
                'AccountType' => 'Accounts Payable',
                'AccountSubType' => 'AccountsPayable',
                'Active' => true,
                'SubAccount' => true,
                'ParentRef' => ['value' => '33', 'name' => 'Accounts Payable (A/P)'],
            ],
            [
                'Id' => '33',
                'Name' => 'Accounts Payable (A/P)',
                'AccountType' => 'Accounts Payable',
                'AccountSubType' => 'AccountsPayable',
                'Active' => true,
                'SubAccount' => false,
                'AcctNum' => null,
            ],
        ];
    }

    private function fakeAccounts(array $rows): void
    {
        // QuickBooks returns account records under QueryResponse.Account.
        // Preserve deliberately malformed responses used by error tests.
        $queryResponse = array_is_list($rows)
            ? ['Account' => $rows]
            : $rows;

        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/v3/company/*/query*'
                => Http::response([
                    'QueryResponse' => $queryResponse,
                ], 200),
        ]);
    }
}
