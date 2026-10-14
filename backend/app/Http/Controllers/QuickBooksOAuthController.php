<?php

namespace App\Http\Controllers;

use App\Models\QuickBooksConnection;
use App\Models\User;
use App\Services\QuickBooksOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class QuickBooksOAuthController extends Controller
{
    private const STATE_TTL_MINUTES = 10;
    private const STATE_PREFIX = 'quickbooks:oauth:state:';

    public function connect(Request $request, QuickBooksOAuthService $oauth): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;
        if (! $tenantId) {
            return response()->json(['success' => false, 'message' => 'An active tenant is required.'], 403);
        }

        $state = Str::random(64);
        Cache::put($this->stateKey($state), [
            'tenant_id' => (int) $tenantId,
            'user_id' => (int) $request->user()->id,
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        try {
            return response()->json([
                'success' => true,
                'data' => ['authorization_url' => $oauth->authorizationUrl($state)],
            ]);
        } catch (Throwable $exception) {
            Cache::forget($this->stateKey($state));
            return response()->json([
                'success' => false,
                'message' => 'QuickBooks connection is not configured yet.',
            ], 503);
        }
    }

    /** Intuit redirects here; OAuth state—not a logged-in browser session—authenticates this callback. */
    public function callback(Request $request, QuickBooksOAuthService $oauth): RedirectResponse
    {
        $state = $request->query('state');
        $stateData = is_string($state) && $state !== ''
            ? Cache::pull($this->stateKey($state))
            : null;

        if (! is_array($stateData) || empty($stateData['tenant_id']) || empty($stateData['user_id'])) {
            return $this->frontendRedirect('invalid_state');
        }

        if ($request->filled('error')) {
            return $this->frontendRedirect('authorization_denied');
        }

        $code = $request->query('code');
        $realmId = $request->query('realmId');
        if (! is_string($code) || $code === '' || strlen($code) > 512
            || ! is_string($realmId) || $realmId === '' || strlen($realmId) > 128) {
            return $this->frontendRedirect('invalid_callback');
        }

        try {
            // Confirm the initiating user still belongs to the initiating tenant.
            User::query()
                ->where('tenant_id', (int) $stateData['tenant_id'])
                ->findOrFail((int) $stateData['user_id']);

            // Authorization codes are one-use: exchange them once and never retry automatically.
            $tokens = $oauth->exchangeAuthorizationCode($code);
            $tokenAttributes = $oauth->tokenAttributes($tokens);

            QuickBooksConnection::query()->updateOrCreate(
                [
                    'tenant_id' => (int) $stateData['tenant_id'],
                    'realm_id' => $realmId,
                ],
                $tokenAttributes + [
                    'connected_by' => (int) $stateData['user_id'],
                    'connection_status' => 'connected',
                    'company_name' => null,
                ]
            );

            return $this->frontendRedirect('connected');
        } catch (Throwable $exception) {
            // Keep the code, tokens, realmId and Intuit response out of logs and redirects.
            Log::warning('QuickBooks OAuth callback failed.', [
                'exception' => $exception::class,
            ]);

            return $this->frontendRedirect('connection_failed');
        }
    }

    public function status(Request $request): JsonResponse
    {
        $connections = QuickBooksConnection::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderByDesc('connected_at')
            ->get([
                'id', 'realm_id', 'company_name', 'connection_status',
                'access_token_expires_at', 'refresh_token_expires_at',
                'connected_at', 'last_synced_at', 'created_at',
            ]);

        return response()->json(['success' => true, 'data' => $connections]);
    }

    public function disconnect(
        Request $request,
        QuickBooksConnection $connection,
        QuickBooksOAuthService $oauth
    ): JsonResponse {
        // Hide other tenants' connection IDs instead of revealing that they exist.
        abort_unless((int) $connection->tenant_id === (int) $request->user()->tenant_id, 404);

        $oauth->revokeRefreshToken($connection->refresh_token);

        $connection->forceFill([
            'connection_status' => 'disconnected',
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'QuickBooks was disconnected from Bizflow.',
        ]);
    }

    private function stateKey(string $state): string
    {
        return self::STATE_PREFIX.hash('sha256', $state);
    }

    private function frontendRedirect(string $result): RedirectResponse
    {
        $url = (string) config('services.quickbooks.frontend_return_url', config('app.url'));
        $separator = str_contains($url, '?') ? '&' : '?';

        return redirect()->away($url.$separator.http_build_query(['quickbooks' => $result]));
    }
}
