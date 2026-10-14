<?php

namespace App\Http\Controllers;

use App\Models\QuickBooksConnection;
use App\Services\QuickBooksAccountingApiService;
use App\Services\QuickBooksApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class QuickBooksCompanyInfoController extends Controller
{
    public function show(
        Request $request,
        QuickBooksConnection $connection,
        QuickBooksAccountingApiService $quickBooks,
    ): JsonResponse {
        // Hide foreign-tenant connection IDs without making an external API request.
        abort_unless(
            (int) $connection->tenant_id === (int) $request->user()->tenant_id,
            404,
        );

        try {
            $company = $quickBooks->companyInfo($connection);

            $connection->forceFill([
                'company_name' => $company['company_name'] ?: $company['legal_name'],
            ])->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'connection_id' => $connection->id,
                    'realm_id' => $connection->realm_id,
                    'company' => $company,
                ],
            ]);
        } catch (QuickBooksApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        } catch (Throwable $exception) {
            // Never log access tokens, refresh tokens, provider payloads, or request headers.
            Log::warning('QuickBooks company information request failed.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'QuickBooks company information could not be retrieved.',
            ], 502);
        }
    }
}
