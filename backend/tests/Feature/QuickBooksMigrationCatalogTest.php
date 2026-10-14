<?php

namespace Tests\Feature;

use App\Models\QuickBooksConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuickBooksMigrationCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.quickbooks.client_id' => 'test-client-id',
            'services.quickbooks.client_secret' => 'test-client-secret',
            'services.quickbooks.redirect_uri' => 'http://localhost:8000/api/integrations/quickbooks/callback',
            'services.quickbooks.environment' => 'sandbox',
        ]);
    }

    public function test_catalog_requires_authentication(): void
    {
        $connection = $this->connectionFor($this->userForTenant());

        $this->getJson($this->catalogEndpoint($connection))->assertUnauthorized();
    }

    public function test_catalog_does_not_query_another_tenants_connection(): void
    {
        $user = $this->userForTenant();
        $connection = $this->connectionFor($this->userForTenant());
        Http::fake();

        $this->actingAs($user)
            ->getJson($this->catalogEndpoint($connection))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_catalog_returns_count_for_each_supported_migration_entity(): void
    {
        $user = $this->userForTenant();
        $connection = $this->connectionFor($user, [
            'realm_id' => '9341458483714643',
            'access_token' => 'valid-access-token',
            'access_token_expires_at' => now()->addHour(),
        ]);

        Http::fake(function (ClientRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $queryParams);
            $statement = $queryParams['query'] ?? '';

            if (preg_match('/select count\(\*\) from ([A-Za-z]+)/i', $statement, $matches)) {
                return Http::response(['QueryResponse' => ['totalCount' => 7]], 200);
            }

            return Http::response(['QueryResponse' => []], 200);
        });

        $response = $this->actingAs($user)->getJson($this->catalogEndpoint($connection));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(9, 'data.entities')
            ->assertJsonPath('data.entities.0.entity_type', 'customers')
            ->assertJsonPath('data.entities.0.record_count', 7)
            ->assertJsonPath('data.entities.4.entity_type', 'bills')
            ->assertJsonPath('data.entities.6.entity_type', 'chart_of_accounts');

        Http::assertSentCount(9);
    }

    public function test_entity_preview_returns_count_and_limited_normalized_samples(): void
    {
        $user = $this->userForTenant();
        $connection = $this->connectionFor($user, [
            'realm_id' => '9341458483714643',
            'access_token' => 'valid-access-token',
            'access_token_expires_at' => now()->addHour(),
        ]);

        Http::fake(function (ClientRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $queryParams);
            $statement = $queryParams['query'] ?? '';

            if (str_contains(strtolower($statement), 'count(*)')) {
                return Http::response(['QueryResponse' => ['totalCount' => 12]], 200);
            }

            return Http::response([
                'QueryResponse' => [
                    'Customer' => [[
                        'Id' => '44',
                        'DisplayName' => 'Sandbox Customer',
                        'CompanyName' => 'Sandbox Customer Ltd',
                        'Active' => true,
                        'PrimaryEmailAddr' => ['Address' => 'private@example.test'],
                    ]],
                ],
            ], 200);
        });

        $response = $this->actingAs($user)->getJson($this->previewEndpoint($connection, 'customers'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.record_count', 12)
            ->assertJsonPath('data.sample_count', 1)
            ->assertJsonPath('data.more_records_available', true)
            ->assertJsonPath('data.samples.0.quickbooks_id', '44')
            ->assertJsonPath('data.samples.0.name', 'Sandbox Customer')
            ->assertJsonMissingPath('data.samples.0.PrimaryEmailAddr');

        Http::assertSentCount(2);
    }

    public function test_unknown_entity_type_is_rejected_without_provider_request(): void
    {
        $user = $this->userForTenant();
        $connection = $this->connectionFor($user);
        Http::fake();

        $this->actingAs($user)
            ->getJson($this->previewEndpoint($connection, 'arbitrary_query'))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    private function userForTenant(): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function connectionFor(User $user, array $overrides = []): QuickBooksConnection
    {
        return QuickBooksConnection::query()->create(array_merge([
            'tenant_id' => $user->tenant_id,
            'connected_by' => $user->id,
            'realm_id' => 'realm-'.fake()->unique()->numerify('########'),
            'connection_status' => 'connected',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'scopes' => ['com.intuit.quickbooks.accounting'],
            'connected_at' => now(),
        ], $overrides));
    }

    private function catalogEndpoint(QuickBooksConnection $connection): string
    {
        return '/api/integrations/quickbooks/connections/'.$connection->id.'/migration-catalog';
    }

    private function previewEndpoint(QuickBooksConnection $connection, string $entityType): string
    {
        return $this->catalogEndpoint($connection).'/'.$entityType;
    }
}
