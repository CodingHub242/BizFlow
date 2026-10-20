<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $query = Category::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());

            $query->where('name', 'like', '%' . $search . '%');
        }

        $categories = $query->paginate(
            min(max((int) $request->input('per_page', 100), 1), 100)
        );

        return response()->json([
            'success' => true,
            'data' => CategoryResource::collection(
                $categories->items()
            ),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'last_page' => $categories->lastPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = Category::create([
            ...$validated,
            'tenant_id' => $tenantId,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully.',
            'data' => new CategoryResource($category),
        ], 201);
    }

    public function show(Request $request, int $category): JsonResponse
    {
        $item = Category::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($category);

        return response()->json([
            'success' => true,
            'data' => new CategoryResource($item),
        ]);
    }

    public function update(
        Request $request,
        int $category
    ): JsonResponse {
        $tenantId = $request->user()->tenant_id;

        $item = Category::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($category);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($item->id),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ]);

        $item->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully.',
            'data' => new CategoryResource($item->fresh()),
        ]);
    }

    public function destroy(
        Request $request,
        int $category
    ): JsonResponse {
        $item = Category::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($category);

        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully.',
        ]);
    }
}
