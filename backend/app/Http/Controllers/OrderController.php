<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Models\OrderPayment;
use App\Services\OrderPaymentService;
use App\OrderPaymentMethod;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => [
                'sometimes',
                'nullable',
                Rule::enum(\App\OrderStatus::class),
            ],
            'payment_status' => [
                'sometimes',
                'nullable',
                Rule::enum(\App\PaymentStatus::class),
            ],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Order::query()
            ->where('tenant_id', $tenantId)
            ->with(['items.catalogItem', 'customer', 'branch', 'payments'])
            ->latest();

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'ilike', "%{$search}%");
            });
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['payment_status'])) {
            $query->where(
                'payment_status',
                $validated['payment_status']
            );
        }

        if (!empty($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }

        if (!empty($validated['customer_id'])) {
            $query->where('customer_id', $validated['customer_id']);
        }

        return response()->json(
            $query->paginate($validated['per_page'] ?? 15)
        );
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $record = Order::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with([
                'items.catalogItem',
                'customer',
                'branch',
                'creator',
                'payments',
                'fulfillmentRequests',
            ])
            ->findOrFail($order);

        return response()->json(['data' => $record]);
    }

    public function store(
        Request $request,
        OrderService $orderService
    ): JsonResponse {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'order_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('orders', 'order_number')
                    ->where('tenant_id', $request->user()->tenant_id),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.catalog_item_id' => [
                'required',
                'integer',
                'distinct',
                'min:1',
            ],
            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,3',
            ],
            'items.*.discount' => [
                'sometimes',
                'numeric',
                'min:0',
            ],
            'items.*.tax' => [
                'sometimes',
                'numeric',
                'min:0',
            ],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'tax' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        // Never accept tenant, creator, status, or payment status
        // from the client.
        $validated['tenant_id'] = $request->user()->tenant_id;
        $validated['created_by'] = $request->user()->id;
        $validated['status'] = 'draft';
        $validated['payment_status'] = 'unpaid';

        $order = $orderService->create($validated);

        $order->load([
            'items.catalogItem',
            'customer',
            'branch',
            'creator',
        ]);

        return response()->json([
            'message' => 'Sales order created successfully.',
            'data' => $order,
        ], 201);
    }


    public function storePayment(Request $request,int $order,OrderPaymentService $paymentService): JsonResponse {
        $user = $request->user();

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'method' => [
                'required',
                Rule::enum(\App\OrderPaymentMethod::class),
            ],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'paid_at' => ['required', 'date'],
        ]);

        // Resolve the order within the authenticated tenant.
        $record = Order::query()
            ->where('tenant_id', $user->tenant_id)
            ->findOrFail($order);

        $payment = $paymentService->create([
            'tenant_id' => $user->tenant_id,
            'order_id' => $record->id,
            'recorded_by' => $user->id,
            'amount' => $validated['amount'],
            'method' => $validated['method'],
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'paid_at' => $validated['paid_at'],
        ]);

        return response()->json([
            'message' => 'Payment recorded successfully.',
            'data' => $payment,
            'payment_status' => $record->fresh()->payment_status,
        ], 201);
    }


  public function fulfill(
        Request $request,
        int $order,
        \App\Services\OrderFulfillmentService $fulfillmentService
    ): JsonResponse {
        $record = Order::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($order);

        $fulfilledOrder = $fulfillmentService->fulfill($record);

        $isComplete = $fulfilledOrder->status === \App\OrderStatus::FULFILLED;

        return response()->json([
            'message' => $isComplete
                ? 'Order fulfilled successfully.'
                : 'Order partially fulfilled. Some items still require stock.',
            'data' => $fulfilledOrder,
            'fulfillment' => [
                'status' => $fulfilledOrder->status->value,
                'is_complete' => $isComplete,
                'requests' => $fulfilledOrder->fulfillmentRequests,
            ],
        ]);
    }

    public function prepareFulfillment(
    Request $request,
    int $order,
    \App\Services\OrderFulfillmentService $fulfillmentService
): JsonResponse {
    $record = Order::query()
        ->where('tenant_id', $request->user()->tenant_id)
        ->with('items.catalogItem')
        ->findOrFail($order);

    $preparedOrder = $fulfillmentService->prepareRequests($record);

    return response()->json([
        'message' => 'Fulfillment requests prepared successfully.',
        'data' => $preparedOrder,
        'fulfillment' => [
            'status' => $preparedOrder->status->value,
            'requests' => $preparedOrder->fulfillmentRequests,
        ],
    ]);
}

    public function update(Request $request,int $order,OrderService $orderService): JsonResponse {
        $tenantId = $request->user()->tenant_id;

        $record = Order::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($order);

        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'customer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'order_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('orders', 'order_number')
                    ->where('tenant_id', $tenantId)
                    ->ignore($record->id),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.catalog_item_id' => [
                'required',
                'integer',
                'distinct',
                'min:1',
            ],
            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,3',
            ],
            'items.*.discount' => ['sometimes', 'numeric', 'min:0'],
            'items.*.tax' => ['sometimes', 'numeric', 'min:0'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'tax' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $updatedOrder = $orderService->update($record, $validated);

        return response()->json([
            'message' => 'Sales order updated successfully.',
            'data' => $updatedOrder,
        ]);
    }

    public function destroy(Request $request,int $order,OrderService $orderService): JsonResponse {
        $record = Order::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($order);

        $orderService->delete($record);

        return response()->json([
            'message' => 'Sales order deleted successfully.',
        ]);
    }

}
