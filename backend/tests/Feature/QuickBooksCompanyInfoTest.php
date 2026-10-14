<?php

namespace Tests\Feature;

use App\Models\QuickBooksConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuickBooksCompanyInfoTest extends TestCase
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

    public function test_company_info_requires_authentication(): void
    {
        $connection = $this->connectionFor($this->userForTenant());

        $this->getJson($this->endpoint($connection))->assertUnauthorized();
    }

    public function test_company_info_does_not_access_another_tenants_connection(): void
    {
        $user = $this->userForTenant();
        $otherUser = $this->userForTenant();
        $connection = $this->connectionFor($otherUser);

        Http::fake();

        $this->actingAs($user)
            ->getJson($this->endpoint($connection))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_company_info_uses_sandbox_get_request_and_updates_company_name(): void
    {
        $user = $this->userForTenant();
        $connection = $this->connectionFor($user, [
            'realm_id' => '9341458230',
            'access_token' => 'valid-access-token',
            'access_token_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/v3/company/9341458230/companyinfo/9341458230' => Http::response([
                'CompanyInfo' => [
                    'Id' => '9341458230',
                    'CompanyName' => 'Sandbox Bicycle Shop',
                    'LegalName' => 'Sandbox Bicycle Shop LLC',
                    'Country' => 'US',
                    'Email' => ['Address' => 'owner@example.test'],
                    'PrimaryPhone' => ['FreeFormNumber' => '555-0100'],
                    'FiscalYearStartMonth' => 'January',
                    'CompanyAddr' => [
                        'Line1' => '100 Main Street',
                        'City' => 'Mountain View',
                        'CountrySubDivisionCode' => 'CA',
                        'PostalCode' => '94043',
                        'Country' => 'US',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)->getJson($this->endpoint($connection));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company.company_name', 'Sandbox Bicycle Shop')
            ->assertJsonPath('data.company.legal_name', 'Sandbox Bicycle Shop LLC')
            ->assertJsonPath('data.company.email', 'owner@example.test')
            ->assertJsonPath('data.company.address.city', 'Mountain View');

        $this->assertSame('Sandbox Bicycle Shop', $connection->fresh()->company_name);

        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
            && $request->url() === 'https://sandbox-quickbooks.api.intuit.com/v3/company/9341458230/companyinfo/9341458230'
            && $request->hasHeader('Authorization', 'Bearer valid-access-token')
        );
    }

    public function test_expired_access_token_is_refreshed_before_company_info_request(): void
    {
        $user = $this->userForTenant();
        $connection = $this->connectionFor($user, [
            'realm_id' => 'realm-expired',
            'access_token' => 'expired-access-token',
            'refresh_token' => 'old-refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
                'access_token' => 'rotated-access-token',
                'refresh_token' => 'rotated-refresh-token',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 8640000,
                'token_type' => 'bearer',
            ], 200),
            'https://sandbox-quickbooks.api.intuit.com/v3/company/realm-expired/companyinfo/realm-expired' => Http::response([
                'CompanyInfo' => ['Id' => 'realm-expired', 'CompanyName' => 'Rotated Token Company'],
            ], 200),
        ]);

        $this->actingAs($user)
            ->getJson($this->endpoint($connection))
            ->assertOk()
            ->assertJsonPath('data.company.company_name', 'Rotated Token Company');

        $connection->refresh();
        $this->assertSame('rotated-access-token', $connection->access_token);
        $this->assertSame('rotated-refresh-token', $connection->refresh_token);

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'old-refresh-token'
        );
        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
            && $request->url() === 'https://sandbox-quickbooks.api.intuit.com/v3/company/realm-expired/companyinfo/realm-expired'
            && $request->hasHeader('Authorization', 'Bearer rotated-access-token')
        );
    }

    public function test_provider_error_body_is_not_returned_to_client(): void
    {
        $user = $this->userForTenant();
        $connection = $this->connectionFor($user, [
            'access_token' => 'valid-access-token',
            'access_token_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://sandbox-quickbooks.api.intuit.com/*' => Http::response([
                'Fault' => ['Error' => [['Message' => 'PRIVATE_PROVIDER_DIAGNOSTIC']]],
            ], 500),
        ]);

        $response = $this->actingAs($user)->getJson($this->endpoint($connection));

        $response->assertStatus(502);
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_DIAGNOSTIC', $response->getContent());
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

    private function endpoint(QuickBooksConnection $connection): string
    {
        return '/api/integrations/quickbooks/connections/'.$connection->id.'/company-info';
    }
}
