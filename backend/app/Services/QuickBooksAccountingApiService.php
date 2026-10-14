<?php

namespace App\Services;

use App\Models\QuickBooksConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only requests to the QuickBooks Online Accounting API.
 * This service intentionally exposes no mutation methods.
 */
class QuickBooksAccountingApiService
{
    public function __construct(private QuickBooksOAuthService $oauth)
    {
    }

    /** Return a normalized subset of the connected QuickBooks company's information. */
    public function companyInfo(QuickBooksConnection $connection): array
    {
        if ($connection->connection_status !== 'connected') {
            throw new QuickBooksApiException('This QuickBooks connection is not active.', 409);
        }

        $connection = $this->ensureFreshAccessToken($connection->fresh() ?? $connection);
        $response = $this->requestCompanyInfo($connection);

        if ($response->status() === 401) {
            $connection = $this->refreshForApiRetry($connection);
            $response = $this->requestCompanyInfo($connection);
        }

        if ($response->status() === 401) {
            $connection->forceFill(['connection_status' => 'reauthorization_required'])->save();
            throw new QuickBooksApiException(
                'QuickBooks authorization has expired. Reconnect the company and try again.',
                401,
            );
        }

        if (! $response->successful()) {
            $status = $response->status() === 429 ? 503 : 502;
            throw new QuickBooksApiException(
                'Unable to retrieve company information from QuickBooks. Please try again.',
                $status,
            );
        }

        $company = $response->json('CompanyInfo');
        if (! is_array($company)) {
            throw new QuickBooksApiException(
                'QuickBooks returned an unexpected company information response.',
                502,
            );
        }

        $address = $company['CompanyAddr'] ?? [];

        return [
            'id' => isset($company['Id']) ? (string) $company['Id'] : $connection->realm_id,
            'company_name' => $company['CompanyName'] ?? null,
            'legal_name' => $company['LegalName'] ?? null,
            'country' => $company['Country'] ?? null,
            'email' => data_get($company, 'Email.Address'),
            'phone' => data_get($company, 'PrimaryPhone.FreeFormNumber'),
            'fiscal_year_start_month' => $company['FiscalYearStartMonth'] ?? null,
            'address' => [
                'line1' => $address['Line1'] ?? null,
                'line2' => $address['Line2'] ?? null,
                'city' => $address['City'] ?? null,
                'region' => $address['CountrySubDivisionCode'] ?? null,
                'postal_code' => $address['PostalCode'] ?? null,
                'country' => $address['Country'] ?? null,
            ],
        ];
    }

    /**
     * Run a read-only QuickBooks Query Language statement and return QueryResponse.
     * Callers must construct statements from a fixed allow-list, never user input.
     */
    public function query(QuickBooksConnection $connection, string $statement): array
    {
        $connection = $connection->fresh() ?? $connection;

        if ($connection->connection_status !== 'connected') {
            throw new QuickBooksApiException('This QuickBooks connection is not active.', 409);
        }

        $connection = $this->ensureFreshAccessToken($connection);
        $response = $this->requestQuery($connection, $statement);

        if ($response->status() === 401) {
            $connection = $this->refreshForApiRetry($connection);
            $response = $this->requestQuery($connection, $statement);
        }

        if ($response->status() === 401) {
            $connection->forceFill(['connection_status' => 'reauthorization_required'])->save();
            throw new QuickBooksApiException(
                'QuickBooks authorization has expired. Reconnect the company and try again.',
                401,
            );
        }

        if (! $response->successful()) {
            $status = $response->status() === 429 ? 503 : 502;
            throw new QuickBooksApiException(
                'Unable to read records from QuickBooks. Please try again.',
                $status,
            );
        }

        $queryResponse = $response->json('QueryResponse');
        if (! is_array($queryResponse)) {
            throw new QuickBooksApiException(
                'QuickBooks returned an unexpected query response.',
                502,
            );
        }

        return $queryResponse;
    }

    private function ensureFreshAccessToken(QuickBooksConnection $connection): QuickBooksConnection
    {
        $expiresAt = $connection->access_token_expires_at;
        $tokenNeedsRefresh = ! $connection->access_token
            || ! $expiresAt
            || $expiresAt->lessThanOrEqualTo(now()->addMinutes(2));

        if (! $tokenNeedsRefresh) {
            return $connection;
        }

        return $this->refreshForApiRetry($connection);
    }

    private function refreshForApiRetry(QuickBooksConnection $connection): QuickBooksConnection
    {
        if (! $connection->refresh_token) {
            $connection->forceFill(['connection_status' => 'reauthorization_required'])->save();
            throw new QuickBooksApiException(
                'QuickBooks reconnection is required before company information can be read.',
                401,
            );
        }

        try {
            return $this->oauth->refreshConnection($connection);
        } catch (Throwable $exception) {
            throw new QuickBooksApiException(
                'Could not refresh QuickBooks authorization. Reconnect the company and try again.',
                401,
                $exception,
            );
        }
    }

    private function requestCompanyInfo(QuickBooksConnection $connection): Response
    {
        $realmId = rawurlencode((string) $connection->realm_id);
        $url = $this->apiBaseUrl()."/v3/company/{$realmId}/companyinfo/{$realmId}";

        return Http::withToken((string) $connection->access_token)
            ->acceptJson()
            ->timeout(20)
            ->get($url);
    }

    private function requestQuery(QuickBooksConnection $connection, string $statement): Response
    {
        $realmId = rawurlencode((string) $connection->realm_id);
        $url = $this->apiBaseUrl()."/v3/company/{$realmId}/query";

        return Http::withToken((string) $connection->access_token)
            ->acceptJson()
            ->timeout(20)
            ->get($url, ['query' => $statement]);
    }

    private function apiBaseUrl(): string
    {
        return match (strtolower((string) config('services.quickbooks.environment', 'sandbox'))) {
            'sandbox' => 'https://sandbox-quickbooks.api.intuit.com',
            'production' => 'https://quickbooks.api.intuit.com',
            default => throw new QuickBooksApiException(
                'QuickBooks environment must be sandbox or production.',
                500,
            ),
        };
    }
}
