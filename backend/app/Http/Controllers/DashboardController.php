<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;
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
            (int) $user->tenant_id,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
        );

        // Fail closed: hide profit if the permission is missing or not assigned.
        $profitPermissionExists = Permission::query()
            ->where('name', 'reports.view_profit')
            ->where('guard_name', 'web')
            ->exists();

        $canViewProfit = $profitPermissionExists
            && $user->hasPermissionTo('reports.view_profit', 'web');

        if (! $canViewProfit) {
            unset($result['gross_profit']);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }


    public function activity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => [
                'nullable',
                'in:invoice,payment,expense',
            ],
            'date_from' => ['nullable', 'date'],
            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],
        ]);

        $tenantId = (int) $request->user()->tenant_id;

        $paginator = $this->dashboardService->activityFeed(
            $tenantId,
            $validated['type'] ?? null,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
            (int) ($validated['per_page'] ?? 20),
        );

        return response()->json([
            'success' => true,

            'data' => collect($paginator->items())
                ->map(fn ($item) => [
                    'id' => (int) $item->source_id,
                    'type' => $item->type,
                    'description' => $item->description,
                    'amount' => (float) $item->amount,
                    'timestamp' => $item->activity_at,
                ])
                ->values(),

            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}