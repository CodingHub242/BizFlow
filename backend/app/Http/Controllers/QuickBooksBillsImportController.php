<?php

namespace App\Http\Controllers;

use App\Models\QuickBooksConnection;
use App\Services\QuickBooksApiException;
use App\Services\QuickBooksBillsImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class QuickBooksBillsImportController extends Controller
{
    public function store(
        Request $request,
        QuickBooksConnection $connection,
        QuickBooksBillsImporter $importer,
    ): JsonResponse {
        abort_unless(
            (int) $connection->tenant_id === (int) $request->user()->tenant_id,
            404,
        );

        setPermissionsTeamId((int) $request->user()->tenant_id);
        abort_unless($request->user()->hasPermissionTo('migration.import'), 403);

        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $importer->import(
                $connection,
                (int) $request->user()->tenant_id,
                (int) $validated['branch_id'],
                (int) $request->user()->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'QuickBooks bills import completed.',
                'data' => $result,
            ]);
        } catch (QuickBooksApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        } catch (Throwable $exception) {
            Log::warning('QuickBooks bills import failed.', [
                'connection_id' => $connection->id,
                'tenant_id' => $request->user()->tenant_id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to import QuickBooks bills right now.',
            ], 500);
        }
    }
}
