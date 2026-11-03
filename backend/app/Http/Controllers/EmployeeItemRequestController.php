<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveEmployeeItemRequest;
use App\Http\Requests\IssueEmployeeItemRequest;
use App\Http\Requests\RejectEmployeeItemRequest;
use App\Http\Requests\ReturnEmployeeItemRequest;
use App\Http\Requests\StoreEmployeeItemRequest;
use App\Http\Resources\EmployeeItemRequestResource;
use App\Models\EmployeeItemRequest;
use App\Services\EmployeeItemRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeItemRequestController extends Controller
{
    private function authorizeSettings(
        Request $request,
        string $permission
    ): void {
        $user = $request->user();

        abort_unless(
            $user && $user->tenant_id,
            403,
            'User tenant does not exist.'
        );

        setPermissionsTeamId($user->tenant_id);

        abort_unless(
            $user->hasPermissionTo($permission, 'web'),
            403,
            'You do not have permission to perform this action.'
        );
    }

    private function baseQuery(int $tenantId)
    {
        return EmployeeItemRequest::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'employee.user:id,name,email',
                'branch',
                'approvedBy:id,name,email',
                'rejectedBy:id,name,email',
                'cancelledBy:id,name,email',
                'items.catalogItem',
                'issues.issuedBy:id,name,email',
                'issues.items.catalogItem',
                'returns.receivedBy:id,name,email',
                'returns.items',
            ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSettings(
            $request,
            'employee_item_requests.view'
        );

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'max:50'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'employee_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;

        $query = $this->baseQuery($tenantId);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }

        if (!empty($validated['employee_id'])) {
            $query->where(
                'requested_by_employee_id',
                $validated['employee_id']
            );
        }

        if (!empty($validated['search'])) {
            $search = trim($validated['search']);

            $query->where(function ($subQuery) use ($search) {
                $subQuery
                    ->where('request_number', 'LIKE', "%{$search}%")
                    ->orWhereHas('employee', function ($employeeQuery) use ($search) {
                        $employeeQuery->whereHas(
                            'user',
                            function ($userQuery) use ($search) {
                                $userQuery
                                    ->where('name', 'LIKE', "%{$search}%")
                                    ->orWhere('email', 'LIKE', "%{$search}%");
                            }
                        );
                    });
            });
        }

        $requests = $query
            ->latest('created_at')
            ->latest('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => EmployeeItemRequestResource::collection(
                $requests->items()
            ),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function show(
        Request $request,
        int $requestId
    ): JsonResponse {
        $this->authorizeSettings(
            $request,
            'employee_item_requests.view'
        );

        $itemRequest = $this->baseQuery(
            (int) $request->user()->tenant_id
        )->findOrFail($requestId);

        return response()->json([
            'success' => true,
            'data' => new EmployeeItemRequestResource($itemRequest),
        ]);
    }

    public function store(
        StoreEmployeeItemRequest $request,
        EmployeeItemRequestService $service
    ): JsonResponse {
        $tenantId = (int) $request->user()->tenant_id;
        $validated = $request->validated();

        $itemRequest = $service->create(
            $tenantId,
            $request->user(),
            $validated['items'],
            $validated['branch_id'] ?? null,
            $validated['purpose'] ?? null,
            $validated['notes'] ?? null
        );

        $itemRequest = $this->baseQuery($tenantId)
            ->findOrFail($itemRequest->id);

        return response()->json([
            'success' => true,
            'message' => 'Item request created successfully.',
            'data' => new EmployeeItemRequestResource($itemRequest),
        ], 201);
    }

    public function approve(
        ApproveEmployeeItemRequest $request,
        int $requestId,
        EmployeeItemRequestService $service
    ): JsonResponse {
        $tenantId = (int) $request->user()->tenant_id;

        $itemRequest = $service->approve(
            $tenantId,
            $request->user(),
            $requestId,
            $request->validated()['approved_quantities'] ?? null
        );

        $itemRequest = $this->baseQuery($tenantId)
            ->findOrFail($itemRequest->id);

        return response()->json([
            'success' => true,
            'message' => 'Item request approved successfully.',
            'data' => new EmployeeItemRequestResource($itemRequest),
        ]);
    }

    public function reject(
        RejectEmployeeItemRequest $request,
        int $requestId,
        EmployeeItemRequestService $service
    ): JsonResponse {
        $tenantId = (int) $request->user()->tenant_id;

        $itemRequest = $service->reject(
            $tenantId,
            $request->user(),
            $requestId,
            $request->validated()['rejection_reason']
        );

        $itemRequest = $this->baseQuery($tenantId)
            ->findOrFail($itemRequest->id);

        return response()->json([
            'success' => true,
            'message' => 'Item request rejected successfully.',
            'data' => new EmployeeItemRequestResource($itemRequest),
        ]);
    }

    public function cancel(
        Request $request,
        int $requestId,
        EmployeeItemRequestService $service
    ): JsonResponse {
        $tenantId = (int) $request->user()->tenant_id;

        $itemRequest = $service->cancel(
            $tenantId,
            $request->user(),
            $requestId
        );

        $itemRequest = $this->baseQuery($tenantId)
            ->findOrFail($itemRequest->id);

        return response()->json([
            'success' => true,
            'message' => 'Item request cancelled successfully.',
            'data' => new EmployeeItemRequestResource($itemRequest),
        ]);
    }

    public function issue(
        IssueEmployeeItemRequest $request,
        int $requestId,
        EmployeeItemRequestService $service
    ): JsonResponse {
        $tenantId = (int) $request->user()->tenant_id;
        $validated = $request->validated();

        $itemRequest = $service->issueItems(
            $tenantId,
            $request->user(),
            $requestId,
            $validated['items'],
            $validated['branch_id'] ?? null,
            $validated['notes'] ?? null
        );

        $itemRequest = $this->baseQuery($tenantId)
            ->findOrFail($itemRequest->id);

        return response()->json([
            'success' => true,
            'message' => 'Items issued successfully.',
            'data' => new EmployeeItemRequestResource($itemRequest),
        ]);
    }

    public function returnItems(
        ReturnEmployeeItemRequest $request,
        int $requestId,
        EmployeeItemRequestService $service
    ): JsonResponse {
        $tenantId = (int) $request->user()->tenant_id;
        $validated = $request->validated();

        $itemRequest = $service->returnItems(
            $tenantId,
            $request->user(),
            $requestId,
            $validated['items'],
            $validated['notes'] ?? null
        );

        $itemRequest = $this->baseQuery($tenantId)
            ->findOrFail($itemRequest->id);

        return response()->json([
            'success' => true,
            'message' => 'Returned items recorded successfully.',
            'data' => new EmployeeItemRequestResource($itemRequest),
        ]);
    }
}
