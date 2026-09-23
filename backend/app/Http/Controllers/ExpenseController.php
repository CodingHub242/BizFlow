<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $expenseService) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => ['nullable','integer',],

            'category_id' => ['nullable','integer',],

            'payment_method' => ['nullable',\Illuminate\Validation\Rule::enum(\App\ExpensePaymentMethod::class),],

            'date_from' => ['nullable','date',],

            'date_to' => ['nullable','date','after_or_equal:date_from',],

            'search' => ['nullable','string','max:255',],

            'per_page' => ['nullable','integer','min:1','max:100',],

            'page' => ['nullable','integer','min:1',],
        ]);

        $query = Expense::query()
            ->where('tenant_id', $user->tenant_id)
            ->when(
                $validated['branch_id'] ?? null,
                fn ($query, $branchId) =>
                    $query->where('branch_id', $branchId)
            )
            ->when(
                $validated['category_id'] ?? null,
                fn ($query, $categoryId) =>
                    $query->where('category_id', $categoryId)
            )
            ->when(
                $validated['payment_method'] ?? null,
                fn ($query, $paymentMethod) =>
                    $query->where('payment_method', $paymentMethod)
            )
            ->when(
                $validated['date_from'] ?? null,
                fn ($query, $dateFrom) =>
                    $query->whereDate('expense_date', '>=', $dateFrom)
            )
            ->when(
                $validated['date_to'] ?? null,
                fn ($query, $dateTo) =>
                    $query->whereDate('expense_date', '<=', $dateTo)
            )
            ->when(
                $validated['search'] ?? null,
                function ($query, $search) {
                    $search = strtolower($search);

                    $query->where(function ($query) use ($search) {
                        $query
                            ->whereRaw(
                                'LOWER(description) LIKE ?',
                                ["%{$search}%"]
                            )
                            ->orWhereRaw(
                                'LOWER(reference) LIKE ?',
                                ["%{$search}%"]
                            );
                    });
                }
            )
            ->latest('expense_date')
            ->latest('id');

        $expenses = $query->paginate(
            $validated['per_page'] ?? 15,
            ['*'],
            'page',
            $validated['page'] ?? 1
        );

        return response()->json([
            'success' => true,
            'data' => ExpenseResource::collection($expenses->items()),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
                'per_page' => $expenses->perPage(),
                'total' => $expenses->total(),
            ],
        ]);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $user = $request->user();

        $expense = $this->expenseService->create(
            $user->tenant_id,
            [
                ...$request->validated(),
                'created_by' => $user->id,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Expense created successfully.',
            'data' => new ExpenseResource($expense),
        ], 201);
    }

    public function show(Request $request, Expense $expense): JsonResponse
    {
        if ($expense->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'data' => new ExpenseResource($expense),
        ]);
    }

    public function update(UpdateExpenseRequest $request,Expense $expense): JsonResponse 
    {
        $user = $request->user();

        if ($expense->tenant_id !== $user->tenant_id) {
            abort(404);
        }

        $updatedExpense = $this->expenseService->update(
            $user->tenant_id,
            $expense,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Expense updated successfully.',
            'data' => new ExpenseResource($updatedExpense),
        ]);
    }

    public function destroy(Request $request,Expense $expense): JsonResponse 
    {
        $user = $request->user();

        if ($expense->tenant_id !== $user->tenant_id) {
            abort(404);
        }

        $this->expenseService->delete(
            $user->tenant_id,
            $expense
        );

        return response()->json([
            'success' => true,
            'message' => 'Expense deleted successfully.',
        ]);
    }
}