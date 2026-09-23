<?php

namespace App\Http\Controllers;

use App\Http\Resources\InventoryResource;
use App\Http\Resources\InventoryMovementResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use App\InventoryMovementType;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => ['sometimes', 'integer','min:1'],
            'catalog_item_id' => ['sometimes', 'integer','min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Inventory::query()
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
        if ($inventory->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'data' => new InventoryResource($inventory),
        ]);
    }

    public function receive(Request $request,InventoryService $inventoryService): JsonResponse 
    {
        $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'catalog_item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'integer', 'min:1'],
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
}