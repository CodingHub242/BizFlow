<?php

namespace App\Http\Controllers;

use App\Services\GeneralReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeneralReportController extends Controller
{
    public function __construct(
        private readonly GeneralReportService $reportService,
    ) {}

   
    public function overview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],
        ]);

        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $data = $this->reportService->overview(
            $tenantId,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
            $user->hasPermissionTo('reports.view_profit'),
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

}