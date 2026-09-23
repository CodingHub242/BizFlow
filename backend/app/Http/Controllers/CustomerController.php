<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'address' => $request->validated('address'),
            'company_name' => $request->validated('company_name'),
            'city' => $request->validated('city'),
            'status' => 'active',
            'country' => $request->validated('country'),
            'notes' => $request->validated('notes'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully.',
            'data' => new CustomerResource($customer),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);


        $query = Customer::query()
            ->where('tenant_id', $request->user()->tenant_id);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function ($query) use ($search) {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1),100);

        $customers = $query
            ->latest()
            ->paginate($perPage);


        return response()->json([
            'success' => true,
            'data' => CustomerResource::collection($customers->items()),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'last_page' => $customers->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        if ($customer->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'data' => new CustomerResource($customer),
        ]);
    }

    public function update(UpdateCustomerRequest $request,Customer $customer): JsonResponse 
    {
        if ($customer->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        $customer->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully.',
            'data' => new CustomerResource($customer->fresh()),
        ]);
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        if ($customer->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted successfully.',
        ]);
    }

    public function restore(Request $request, Customer $customer): JsonResponse
    {
        if ($customer->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        if (! $customer->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Customer is not deleted.',
            ], 422);
        }

        $customer->restore();

        return response()->json([
            'success' => true,
            'message' => 'Customer restored successfully.',
            'data' => new CustomerResource($customer->fresh()),
        ]);
    }

}