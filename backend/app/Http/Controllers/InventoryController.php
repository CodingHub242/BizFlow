<?php

namespace App\Http\Controllers;

use App\Http\Resources\InventoryResource;
use App\Http\Resources\InventoryMovementResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\InventoryBatch;
use App\Http\Resources\InventoryBatchResource;
use App\InventoryMovementType;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
     private function authorizeSettings(Request $request,string $permission): void 
    {
        $user = $request->user();

        abort_unless(
            $user && $user->tenant_id,
            403,
            'Does Not Exist'
        );

        setPermissionsTeamId($user->tenant_id);

        abort_unless(
            $user->hasPermissionTo($permission, 'web'),
            403,
            'You do not have permission to perform this action.'
        );
    }

    public function index(Request $request): JsonResponse
    {
         $this->authorizeSettings($request, 'inventory.view');

        $request->validate([
            'branch_id' => ['sometimes', 'integer','min:1'],
            'catalog_item_id' => ['sometimes', 'integer','min:1'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Inventory::query()->with([
            'branch',
            'catalogItem',
        ])
        ->where('tenant_id', $request->user()->tenant_id);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('catalog_item_id')) {
            $query->where(
                'catalog_item_id',
                $request->integer('catalog_item_id')
            );
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());

            $query->whereHas('catalogItem', function ($catalogQuery) use ($search) {
                $catalogQuery
                    ->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('sku', 'LIKE', "%{$search}%");
            });
        }

        $perPage = $request->integer('per_page', 15);

       $inventory = $query
        ->latest('created_at')
        ->latest('id')
        ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => InventoryResource::collection($inventory->items()),
            'meta' => [
                'current_page' => $inventory->currentPage(),
                'last_page' => $inventory->lastPage(),
                'per_page' => $inventory->perPage(),
                'total' => $inventory->total(),
            ],
        ]);
    }

    public function show(Request $request, Inventory $inventory): JsonResponse
    {
         $this->authorizeSettings($request, 'inventory.view');

        if ($inventory->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        //load the relationship data
        $inventory->load(['branch','catalogItem']);

        return response()->json([
            'success' => true,
            'data' => new InventoryResource($inventory),
        ]);
    }

    public function availability(Request $request,InventoryService $inventoryService): JsonResponse 
    {
         $this->authorizeSettings($request, 'inventory.view');
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'catalog_item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $result = $inventoryService->checkAvailability(
            $request->user()->tenant_id,
            $validated['branch_id'],
            $validated['catalog_item_id'],
            (float) $validated['quantity'],
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function receive(Request $request,InventoryService $inventoryService): JsonResponse 
    {
         $this->authorizeSettings($request, 'inventory.receive');
        $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'catalog_item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'integer', 'min:1'],

            'batch_number' => ['nullable', 'string', 'max:100'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        try{
            $inventory = $inventoryService->receiveStock(
                $request->user()->tenant_id,
                $request->integer('branch_id'),
                $request->integer('catalog_item_id'),
                (float) $request->input('quantity'),
                $request->input('notes'),
                $request->input('reference_type'),
                $request->has('reference_id')
                    ? $request->integer('reference_id')
                    : null,
                $request->input('batch_number'),
                $request->has('unit_cost')
                    ? (float) $request->input('unit_cost')
                    : null,
                $request->has('selling_price')
                    ? (float) $request->input('selling_price')
                    : null,
            );
        } catch (\RuntimeException $e) {
            if ($e instanceof ModelNotFoundException) {
                throw $e;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock received successfully.',
            'data' => new InventoryResource($inventory),
        ]);
    }

    public function adjust(Request $request,InventoryService $inventoryService): JsonResponse 
    {
         $this->authorizeSettings($request, 'inventory.adjust');
        $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'catalog_item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $inventory = $inventoryService->adjustStock(
                $request->user()->tenant_id,
                $request->integer('branch_id'),
                $request->integer('catalog_item_id'),
                (float) $request->input('quantity'),
                $request->input('notes'),
            );
        } catch (\RuntimeException $e) {
            if ($e instanceof ModelNotFoundException) {
                throw $e;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock adjusted successfully.',
            'data' => new InventoryResource($inventory),
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
         $this->authorizeSettings($request, 'inventory.view');
        $request->validate([
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'catalog_item_id' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = InventoryMovement::query()
            ->where('tenant_id', $request->user()->tenant_id);

        if ($request->filled('branch_id')) {
            $query->where(
                'branch_id',
                $request->integer('branch_id')
            );
        }

        if ($request->filled('catalog_item_id')) {
            $query->where(
                'catalog_item_id',
                $request->integer('catalog_item_id')
            );
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $perPage = $request->integer('per_page', 15);

        $movements = $query
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => InventoryMovementResource::collection(
                $movements->items()
            ),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
            ],
        ]);
    }

    public function transfer(Request $request,InventoryService $inventoryService): JsonResponse 
    {
         $this->authorizeSettings($request, 'inventory.transfer');
        if ($request->has('source_branch_id') && $request->has('destination_branch_id')) {
            $request->merge([
                'from_branch_id' => $request->input('source_branch_id'),
                'to_branch_id' => $request->input('destination_branch_id')
            ]);
        }
        $request->validate([
            'from_branch_id' => ['required', 'integer', 'min:1'],
            'to_branch_id' => ['required', 'integer', 'min:1'],
            'catalog_item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $result = $inventoryService->transferStock(
                $request->user()->tenant_id,
                $request->integer('from_branch_id'),
                $request->integer('to_branch_id'),
                $request->integer('catalog_item_id'),
                (float) $request->input('quantity'),
                $request->input('notes'),
            );
        } catch (\RuntimeException $e) {
            if ($e instanceof ModelNotFoundException) {
                throw $e;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock transferred successfully.',
            'data' => [
                'from_inventory' => new InventoryResource(
                    $result['from_inventory']
                ),
                'to_inventory' => new InventoryResource(
                    $result['to_inventory']
                ),
            ],
        ]);
    }


    public function openingStock(Request $request,InventoryService $inventoryService): JsonResponse 
    {
         $this->authorizeSettings($request, 'inventory.receive');

        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'catalog_item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $inventory = $inventoryService->setOpeningStock(
                $request->user()->tenant_id,
                (int) $validated['branch_id'],
                (int) $validated['catalog_item_id'],
                (float) $validated['quantity'],
                $validated['notes'] ?? null,
                $validated['reference_type'] ?? null,
                isset($validated['reference_id'])
                    ? (int) $validated['reference_id']
                    : null,
            );
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Opening stock initialized successfully.',
            'data' => new InventoryResource($inventory),
        ], 201);
    }

    public function openingStockCandidates(
    Request $request
): JsonResponse {
    $validated = $request->validate([
        'branch_id' => ['required', 'integer', 'min:1'],
        'search' => ['nullable', 'string', 'max:255'],
        'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
    ]);

    $tenantId = (int) $request->user()->tenant_id;
    $branchId = (int) $validated['branch_id'];

    Branch::query()
        ->where('tenant_id', $tenantId)
        ->findOrFail($branchId);

    $products = CatalogItem::query()
        ->where('tenant_id', $tenantId)
        ->where('type', 'product')
        ->where('track_inventory', true)
        ->where('is_active', true)
        ->when(
            ! empty($validated['search']),
            function ($query) use ($validated) {
                $search = trim($validated['search']);

                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('sku', 'ilike', "%{$search}%");
                });
            }
        )
        ->whereDoesntHave('inventories', function ($query) use (
            $tenantId,
            $branchId
        ) {
            $query
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId);
        })
        ->orderBy('name')
        ->paginate($validated['per_page'] ?? 20);

    return response()->json([
        'success' => true,
        'data' => $products->items(),
        'meta' => [
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
        ],
    ]);
}

public function batches(Request $request): JsonResponse
{
    $this->authorizeSettings($request, 'inventory.view');

    $validated = $request->validate([
        'branch_id' => ['sometimes', 'integer', 'min:1'],
        'catalog_item_id' => ['sometimes', 'integer', 'min:1'],
        'search' => ['sometimes', 'string', 'max:255'],
        'source_type' => ['sometimes', 'string', 'max:100'],
        'status' => ['sometimes', 'in:available,depleted'],
        'received_from' => ['sometimes', 'date'],
        'received_to' => ['sometimes', 'date', 'after_or_equal:received_from'],
        'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        'page' => ['sometimes', 'integer', 'min:1'],
    ]);

    $query = InventoryBatch::query()
        ->where('tenant_id', $request->user()->tenant_id)
        ->with(['branch', 'catalogItem', 'originBatch']);

    if (! empty($validated['branch_id'])) {
        $query->where('branch_id', $validated['branch_id']);
    }

    if (! empty($validated['catalog_item_id'])) {
        $query->where('catalog_item_id', $validated['catalog_item_id']);
    }

    if (! empty($validated['source_type'])) {
        $query->where('source_type', $validated['source_type']);
    }

    if (($validated['status'] ?? null) === 'available') {
        $query->where('quantity_remaining', '>', 0);
    } elseif (($validated['status'] ?? null) === 'depleted') {
        $query->where('quantity_remaining', '<=', 0);
    }

    if (! empty($validated['received_from'])) {
        $query->whereDate('received_at', '>=', $validated['received_from']);
    }

    if (! empty($validated['received_to'])) {
        $query->whereDate('received_at', '<=', $validated['received_to']);
    }

    if (! empty($validated['search'])) {
        $search = trim($validated['search']);

        $query->where(function ($q) use ($search) {
            $q->where('batch_number', 'ilike', "%{$search}%")
                ->orWhereHas('catalogItem', function ($catalogQuery) use ($search) {
                    $catalogQuery
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('sku', 'ilike', "%{$search}%");
                });
        });
    }

    $batches = $query
        ->orderByDesc('received_at')
        ->orderByDesc('id')
        ->paginate($validated['per_page'] ?? 15);

    return response()->json([
        'success' => true,
        'data' => InventoryBatchResource::collection($batches->items()),
        'meta' => [
            'current_page' => $batches->currentPage(),
            'last_page' => $batches->lastPage(),
            'per_page' => $batches->perPage(),
            'total' => $batches->total(),
        ],
    ]);
}
public function showBatch(Request $request, int $batchId): JsonResponse
{
    $this->authorizeSettings($request, 'inventory.view');

    $batch = InventoryBatch::query()
        ->where('tenant_id', $request->user()->tenant_id)
        ->with([
            'branch',
            'catalogItem',
            'originBatch',
            'movements' => fn ($query) => $query->latest('created_at')
                ->latest('id'),
            'allocations' => fn ($query) => $query->latest('created_at')
                ->latest('id'),
        ])
        ->findOrFail($batchId);

    return response()->json([
        'success' => true,
        'data' => new InventoryBatchResource($batch),
    ]);
}

public function inventoryBatches(
    Request $request,
    int $inventoryId
): JsonResponse {
    $this->authorizeSettings($request, 'inventory.view');

    $inventory = Inventory::query()
        ->where('tenant_id', $request->user()->tenant_id)
        ->findOrFail($inventoryId);

    $batches = InventoryBatch::query()
        ->where('tenant_id', $request->user()->tenant_id)
        ->where('branch_id', $inventory->branch_id)
        ->where('catalog_item_id', $inventory->catalog_item_id)
        ->with(['branch', 'catalogItem', 'originBatch'])
        ->orderBy('received_at')
        ->orderBy('id')
        ->paginate($request->integer('per_page', 15));

    return response()->json([
        'success' => true,
        'data' => InventoryBatchResource::collection($batches->items()),
        'meta' => [
            'current_page' => $batches->currentPage(),
            'last_page' => $batches->lastPage(),
            'per_page' => $batches->perPage(),
            'total' => $batches->total(),
        ],
    ]);
}

}