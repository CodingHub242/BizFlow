<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $user = $request->user();

        $result = $this->dashboardService->summary(
            $user->tenant_id,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}