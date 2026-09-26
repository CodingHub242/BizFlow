<?php

namespace App\Http\Controllers;

use App\Http\Resources\CatalogItemResource;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogItemController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:product,service'],
            'sku' => ['nullable','string','max:255',Rule::unique('catalog_items', 'sku')->where(fn ($query) => $query->where('tenant_id', $user->tenant_id)),],
            'description' => ['nullable', 'string'],
            'unit' => ['nullable', 'string', 'max:100'],
            'cost_price' => ['required', 'numeric', 'gte:0'],
            'is_active' => ['sometimes', 'boolean'],
            'selling_price' => ['required', 'numeric', 'gte:0'],
            'tax_rate' => ['nullable', 'numeric', 'gte:0'],
            'track_inventory' => ['boolean'],
        ]);


        $catalogItem = CatalogItem::create([
            'tenant_id' => $user->tenant_id,
            'name' => $request->input('name'),
            'type' => $request->input('type'),
            'sku' => $request->input('sku'),
            'description' => $request->input('description'),
            'unit' => $request->input('unit'),
            'cost_price' => $request->input('cost_price'),
            'selling_price' => $request->input('selling_price'),
            'tax_rate' => $request->input('tax_rate', 0),
            'track_inventory' => $request->boolean('track_inventory'),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Catalog item created successfully.',
            'data' => $catalogItem,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = CatalogItem::query()
        ->where('tenant_id', $request->user()->tenant_id);

        $request->validate([
           'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:product,service'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function ($query) use ($search) {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

       $perPage = $request->integer('per_page', 15);

        $catalogItems = $query
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => CatalogItemResource::collection($catalogItems->items()),
            'meta' => [
                'current_page' => $catalogItems->currentPage(),
                'last_page' => $catalogItems->lastPage(),
                'per_page' => $catalogItems->perPage(),
                'total' => $catalogItems->total(),
            ],
        ]);
    }

    public function show(Request $request, CatalogItem $catalogItem): JsonResponse
    {
        if ($catalogItem->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'data' => new CatalogItemResource($catalogItem),
        ]);
    }

    public function update(Request $request, CatalogItem $catalogItem): JsonResponse
    {
        if ($catalogItem->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', 'in:product,service'],
            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('catalog_items', 'sku')
                    ->where(fn ($query) => $query->where('tenant_id', $request->user()->tenant_id))
                    ->ignore($catalogItem->id),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cost_price' => ['sometimes', 'required', 'numeric', 'gte:0'],
            'selling_price' => ['sometimes', 'required', 'numeric', 'gte:0'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'track_inventory' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('cost_price', $validated) && (float) $validated['cost_price'] !== (float) $catalogItem->cost_price) 
        {
            $user = $request->user();

            setPermissionsTeamId($user->tenant_id);

            abort_unless(
                $user->hasPermissionTo('products.change_cost'),
                403,
                'You do not have permission to change product cost.'
            );
        }

        $catalogItem->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Catalog item updated successfully.',
            'data' => new CatalogItemResource($catalogItem->fresh()),
        ]);
    }

    public function destroy(Request $request, CatalogItem $catalogItem): JsonResponse
    {
        if ($catalogItem->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        $catalogItem->delete();

        return response()->json([
            'success' => true,
            'message' => 'Catalog item deleted successfully.',
        ]);
    }

    public function restore(Request $request, CatalogItem $catalogItem): JsonResponse
    {
        if ($catalogItem->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        if (! $catalogItem->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Catalog item is not deleted.',
            ], 422);
        }

        $catalogItem->restore();

        return response()->json([
            'success' => true,
            'message' => 'Catalog item restored successfully.',
            'data' => new CatalogItemResource($catalogItem->fresh()),
        ]);
    }
}