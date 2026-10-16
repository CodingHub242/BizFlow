<?php

namespace App\Http\Controllers;

use App\Models\QuickBooksConnection;
use App\Services\QuickBooksApiException;
use App\Services\QuickBooksCreditMemoApplicationsReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class QuickBooksCreditMemoApplicationsReconcileController extends Controller
{
    public function store(
        Request $request,
        QuickBooksConnection $connection,
        QuickBooksCreditMemoApplicationsReconciler $reconciler,
    ): JsonResponse {
        abort_unless((int) $connection->tenant_id === (int) $request->user()->tenant_id, 404);

        setPermissionsTeamId((int) $request->user()->tenant_id);
        abort_unless($request->user()->hasPermissionTo('migration.import'), 403);

        try {
            $result = $reconciler->reconcile(
                $connection,
                (int) $request->user()->tenant_id,
            );

            return response()->json([
                'success' => true,
                'message' => 'QuickBooks credit memo application reconciliation completed.',
                'data' => $result,
            ]);
        } catch (QuickBooksApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        } catch (Throwable $exception) {
            Log::warning('QuickBooks credit memo application reconciliation failed.', [
                'connection_id' => $connection->id,
                'tenant_id' => $request->user()->tenant_id,
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to reconcile QuickBooks credit memo applications right now.',
            ], 500);
        }
    }
}
