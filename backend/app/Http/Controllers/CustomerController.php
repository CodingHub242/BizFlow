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

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorizeSettings($request, 'customers.create');

        $data = $request->validated();

        $data['tenant_id'] = $request->user()->tenant_id;
        $data['status'] = 'active';

        $customer = Customer::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully.',
            'data' => new CustomerResource($customer),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
         $this->authorizeSettings($request, 'customers.view');

        $request->validate([
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);


        $query = Customer::query()
            ->where('tenant_id', $request->user()->tenant_id);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function ($query) use ($search) {
                $term = '%' . strtolower($search) . '%';

                $query->whereRaw('LOWER(name) iLIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) iLIKE ?', [$term])
                    ->orWhereRaw('LOWER(phone) iLIKE ?', [$term]);
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
         $this->authorizeSettings($request, 'customers.view');

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
         $this->authorizeSettings($request, 'customers.update');

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
         $this->authorizeSettings($request, 'customers.delete');
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
         $this->authorizeSettings($request, 'customers.update');
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