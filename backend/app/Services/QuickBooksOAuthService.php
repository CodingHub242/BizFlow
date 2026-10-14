<?php

namespace App\Services;

use App\Models\QuickBooksConnection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class QuickBooksOAuthService
{
    private const AUTHORIZE_URL = 'https://appcenter.intuit.com/connect/oauth2';
    private const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    private const REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';
    private const ACCOUNTING_SCOPE = 'com.intuit.quickbooks.accounting';

    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.quickbooks.client_id'),
            'response_type' => 'code',
            'scope' => self::ACCOUNTING_SCOPE,
            'redirect_uri' => config('services.quickbooks.redirect_uri'),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchange an OAuth authorization code exactly once. */
    public function exchangeAuthorizationCode(string $code): array
    {
        $this->assertConfigured();

        return $this->postTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.quickbooks.redirect_uri'),
        ]);
    }

    /** Refresh and persist the latest rotated token pair. */
    public function refreshConnection(QuickBooksConnection $connection): QuickBooksConnection
    {
        $this->assertConfigured();

        if (! $connection->refresh_token) {
            throw new RuntimeException('QuickBooks reconnection is required.');
        }

        try {
            $tokens = $this->postTokenRequest([
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
            ]);
        } catch (RequestException $exception) {
            // Do not log request/response bodies; they can contain sensitive information.
            $connection->forceFill(['connection_status' => 'reauthorization_required'])->save();
            throw new RuntimeException('QuickBooks token refresh failed; reconnect the company.', 0, $exception);
        }

        $attributes = $this->tokenAttributes($tokens);
        unset($attributes['connected_at']); // Preserve the original connection timestamp on refresh.

        $connection->forceFill($attributes + [
            'connection_status' => 'connected',
        ])->save();

        return $connection->refresh();
    }

    /** Best-effort remote revocation. Local credentials are removed by the controller. */
    public function revokeRefreshToken(?string $refreshToken): bool
    {
        if (! $refreshToken || ! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth(
                    (string) config('services.quickbooks.client_id'),
                    (string) config('services.quickbooks.client_secret')
                )
                ->acceptJson()
                ->timeout(15)
                ->post(self::REVOKE_URL, ['token' => $refreshToken]);

            return $response->successful();
        } catch (\Throwable) {
            // The local token will still be removed; do not expose or log the token.
            return false;
        }
    }

    public function tokenAttributes(array $tokens): array
    {
        if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            throw new RuntimeException('QuickBooks did not return the required OAuth tokens.');
        }

        $now = now();

        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'access_token_expires_at' => $now->copy()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'refresh_token_expires_at' => isset($tokens['x_refresh_token_expires_in'])
                ? $now->copy()->addSeconds((int) $tokens['x_refresh_token_expires_in'])
                : null,
            'scopes' => [self::ACCOUNTING_SCOPE],
            'connected_at' => $now,
        ];
    }

    private function postTokenRequest(array $payload): array
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth(
                    (string) config('services.quickbooks.client_id'),
                    (string) config('services.quickbooks.client_secret')
                )
                ->acceptJson()
                ->timeout(20)
                ->post(self::TOKEN_URL, $payload);

            if (! $response->successful()) {
                // Throw a sanitized exception type to the caller; never expose the response body.
                $response->throw();
            }

            return $response->json() ?? [];
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            throw new RuntimeException('Could not reach QuickBooks OAuth services.', 0, $exception);
        }
    }

    private function isConfigured(): bool
    {
        return filled(config('services.quickbooks.client_id'))
            && filled(config('services.quickbooks.client_secret'))
            && filled(config('services.quickbooks.redirect_uri'));
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('QuickBooks OAuth is not configured. Check the server environment variables.');
        }
    }
}
