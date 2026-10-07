<?php

namespace App\Http\Controllers;

use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        //normalize request from frontend
        if(is_string($request->is_active))
        {
            $request->merge([
                'is_active' => $request->boolean('is_active'),
            ]);
        }

        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Branch::query()
            ->where('tenant_id', $request->user()->tenant_id);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function ($query) use ($search) {
                $query
                    ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(address) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(phone) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($request->has('is_active')) {
            $query->where(
                'is_active',
                $request->boolean('is_active')
            );
        }

        $perPage = $request->integer('per_page', 15);

        $branches = $query
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => BranchResource::collection($branches->items()),
            'meta' => [
                'current_page' => $branches->currentPage(),
                'last_page' => $branches->lastPage(),
                'per_page' => $branches->perPage(),
                'total' => $branches->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],

            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('branches', 'code')
                    ->where(fn ($query) =>
                        $query->where('tenant_id', $user->tenant_id)
                    ),
            ],

            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branch = Branch::create([
            'tenant_id' => $user->tenant_id,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'address' => $validated['address'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Branch created successfully.',
            'data' => new BranchResource($branch),
        ], 201);
    }

    public function show(Request $request, Branch $branch): JsonResponse
    {
        if ($branch->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'data' => new BranchResource($branch),
        ]);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        if ($branch->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],

            'code' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('branches', 'code')
                    ->where(fn ($query) =>
                        $query->where('tenant_id', $request->user()->tenant_id)
                    )
                    ->ignore($branch->id),
            ],

            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branch->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Branch updated successfully.',
            'data' => new BranchResource($branch->fresh()),
        ]);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        if ($branch->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        $branch->delete();

        return response()->json([
            'success' => true,
            'message' => 'Branch deleted successfully.',
        ]);
    }

    public function restore(Request $request, Branch $branch): JsonResponse
    {
        if ($branch->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        if (! $branch->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Branch is not deleted.',
            ], 422);
        }

        $branch->restore();

        return response()->json([
            'success' => true,
            'message' => 'Branch restored successfully.',
            'data' => new BranchResource($branch->fresh()),
        ]);
    }
}