<?php

namespace Tests\Feature;

use App\Models\QuickBooksConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuickBooksOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.quickbooks.client_id' => 'test-client-id',
            'services.quickbooks.client_secret' => 'test-client-secret',
            'services.quickbooks.redirect_uri' => 'http://localhost:8000/api/integrations/quickbooks/callback',
            'services.quickbooks.frontend_return_url' => 'http://localhost:8100/settings/integrations',
        ]);

        Cache::flush();
    }

    public function test_connect_requires_authentication(): void
    {
        $this->postJson('/api/integrations/quickbooks/connect')->assertUnauthorized();
    }

    public function test_connect_returns_intuit_authorization_url_and_random_state(): void
    {
        $user = $this->userForTenant();

        $response = $this->actingAs($user)->postJson('/api/integrations/quickbooks/connect');

        $response->assertOk()->assertJsonPath('success', true);
        $authorizationUrl = $response->json('data.authorization_url');
        $this->assertIsString($authorizationUrl);
        $this->assertStringStartsWith('https://appcenter.intuit.com/connect/oauth2?', $authorizationUrl);

        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        $this->assertSame('test-client-id', $query['client_id'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('com.intuit.quickbooks.accounting', $query['scope'] ?? null);
        $this->assertSame(config('services.quickbooks.redirect_uri'), $query['redirect_uri'] ?? null);
        $this->assertGreaterThanOrEqual(40, strlen((string) ($query['state'] ?? '')));
    }

    public function test_callback_rejects_unknown_or_reused_state(): void
    {
        $response = $this->get('/api/integrations/quickbooks/callback?state=not-a-valid-state&code=code123&realmId=123');

        $response->assertRedirect('http://localhost:8100/settings/integrations?quickbooks=invalid_state');
        $this->assertDatabaseCount('quickbooks_connections', 0);
    }

    public function test_callback_exchanges_code_and_stores_tokens_encrypted_for_the_initiating_tenant(): void
    {
        $user = $this->userForTenant();
        $state = str_repeat('s', 64);
        Cache::put('quickbooks:oauth:state:'.hash('sha256', $state), [
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
        ], now()->addMinutes(10));

        Http::fake([
            'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
                'access_token' => 'plain-access-token-for-test',
                'refresh_token' => 'plain-refresh-token-for-test',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 8640000,
                'token_type' => 'bearer',
            ], 200),
        ]);

        $response = $this->get('/api/integrations/quickbooks/callback?'.http_build_query([
            'state' => $state,
            'code' => 'single-use-auth-code',
            'realmId' => '9341458230',
        ]));

        $response->assertRedirect('http://localhost:8100/settings/integrations?quickbooks=connected');
        $connection = QuickBooksConnection::query()->firstOrFail();
        $this->assertSame($user->tenant_id, $connection->tenant_id);
        $this->assertSame('9341458230', $connection->realm_id);
        $this->assertSame('connected', $connection->connection_status);
        $this->assertSame('plain-access-token-for-test', $connection->access_token);
        $this->assertSame('plain-refresh-token-for-test', $connection->refresh_token);

        $storedAccessToken = DB::table('quickbooks_connections')->where('id', $connection->id)->value('access_token');
        $storedRefreshToken = DB::table('quickbooks_connections')->where('id', $connection->id)->value('refresh_token');
        $this->assertNotSame('plain-access-token-for-test', $storedAccessToken);
        $this->assertNotSame('plain-refresh-token-for-test', $storedRefreshToken);
        $this->assertStringNotContainsString('plain-access-token-for-test', (string) $storedAccessToken);
        $this->assertStringNotContainsString('plain-refresh-token-for-test', (string) $storedRefreshToken);

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer'
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'single-use-auth-code'
        );

        // State is single-use, so replaying the same callback cannot make another connection.
        $this->get('/api/integrations/quickbooks/callback?'.http_build_query([
            'state' => $state,
            'code' => 'single-use-auth-code',
            'realmId' => '9341458230',
        ]))->assertRedirect('http://localhost:8100/settings/integrations?quickbooks=invalid_state');
        $this->assertDatabaseCount('quickbooks_connections', 1);
    }

    public function test_status_only_returns_current_tenants_connections_and_never_tokens(): void
    {
        $user = $this->userForTenant();
        $otherUser = $this->userForTenant();

        QuickBooksConnection::query()->create([
            'tenant_id' => $user->tenant_id,
            'connected_by' => $user->id,
            'realm_id' => 'realm-owned-by-this-tenant',
            'connection_status' => 'connected',
            'access_token' => 'secret-access-token',
            'refresh_token' => 'secret-refresh-token',
        ]);
        QuickBooksConnection::query()->create([
            'tenant_id' => $otherUser->tenant_id,
            'connected_by' => $otherUser->id,
            'realm_id' => 'another-tenants-realm',
            'connection_status' => 'connected',
            'access_token' => 'another-secret-token',
            'refresh_token' => 'another-secret-refresh-token',
        ]);

        $response = $this->actingAs($user)->getJson('/api/integrations/quickbooks/status');
        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('secret-access-token', $response->getContent());
        $this->assertStringNotContainsString('secret-refresh-token', $response->getContent());
        $this->assertStringNotContainsString('another-tenants-realm', $response->getContent());
    }

    public function test_tenant_cannot_disconnect_another_tenants_connection(): void
    {
        $user = $this->userForTenant();
        $otherUser = $this->userForTenant();
        $connection = QuickBooksConnection::query()->create([
            'tenant_id' => $otherUser->tenant_id,
            'connected_by' => $otherUser->id,
            'realm_id' => 'foreign-realm',
            'connection_status' => 'connected',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/integrations/quickbooks/connections/'.$connection->id)
            ->assertNotFound();

        $this->assertSame('connected', $connection->fresh()->connection_status);
    }

    private function userForTenant(): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->create(['tenant_id' => $tenant->id]);
    }
}
