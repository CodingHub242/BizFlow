<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\StoreInvoicePaymentRequest;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use App\Models\InvoicePayment;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    public function store(StoreInvoiceRequest $request,InvoiceService $invoiceService): JsonResponse 
    {
        $data = $request->validated();

        $data['tenant_id'] = $request->user()->tenant_id;
        $data['created_by'] = $request->user()->id;

        $invoice = $invoiceService->create($data);

        //Invoice resource and item resource have been used to decide which response are passed
        return response()->json([
            'success' => true,
            'message' => 'Invoice created successfully.',
            'data' => new \App\Http\Resources\InvoiceResource(
                    $invoice->load('items')
                ),
        ], 201);
    }

    public function recordPayment(StoreInvoicePaymentRequest $request,Invoice $invoice,InvoiceService $invoiceService): JsonResponse 
    {
        abort_unless(
            $invoice->tenant_id === $request->user()->tenant_id,
            404
        );

        $data = $request->validated();

        $data['paid_at'] = $data['paid_at'] ?? now();

        $data['tenant_id'] = $request->user()->tenant_id;
        $data['recorded_by'] = $request->user()->id;

        $payment = $invoiceService->recordPayment($invoice, $data);

        return response()->json([
            'success' => true,
            'message' => 'Invoice payment recorded successfully.',
            'data' => new \App\Http\Resources\InvoicePaymentResource($payment),
        ], 201);
    }

    public function show(Invoice $invoice,\Illuminate\Http\Request $request): JsonResponse 
    {
        abort_unless(
            $invoice->tenant_id === $request->user()->tenant_id,
            404
        );

        $invoice->load([
            'items',
            'customer',
            'branch',
            'payments',
            'payments.reversal',
            'creditMemoApplications',
        ]);

        return response()->json([
            'success' => true,
            'data' => new \App\Http\Resources\InvoiceResource($invoice),
        ]);
    }

 
public function index(\Illuminate\Http\Request $request): JsonResponse
{
    $tenantId = (int) $request->user()->tenant_id;

    $query = Invoice::query()
        ->where('tenant_id', $tenantId)
        ->with([
            'items',
            'customer',
            'branch',
            'payments.reversal',
            'creditMemoApplications',
        ]);

    // Search across all invoices belonging to this tenant.
    $search = trim((string) $request->query('search', ''));

    if ($search !== '') {
        $query->where(function ($q) use ($search) {
            $q->where('invoice_number', 'ilike', "%{$search}%")
                ->orWhereRaw(
                    'CAST(invoices.id AS TEXT) ILIKE ?',
                    ["%{$search}%"]
                )
                ->orWhereHas('customer', function ($customerQuery) use ($search) {
                    $customerQuery->where(
                        'name',
                        'ilike',
                        "%{$search}%"
                    )->orWhere(
                        'company_name',
                        'ilike',
                        "%{$search}%"
                    );
                });
        });
    }

    // Map frontend labels to the statuses stored in the database.
    $status = $request->query('status');

    if ($status !== null && $status !== '') {
        $status = match ($status) {
            'issued' => 'sent',
            default => $status,
        };

        $query->where('status', $status);
    }

    $paymentStatus = $request->query('payment_status');

    if ($paymentStatus !== null && $paymentStatus !== '') {
        $paymentStatus = str_replace('-', '_', $paymentStatus);

        $query->where('payment_status', $paymentStatus);
    }

    // Calculate summary values across every matching invoice,
    // not just the current page.
    $matchingCount = (clone $query)->count();

    $matchingTotal = (clone $query)->sum('total');

    $invoices = (clone $query)
        ->latest()
        ->paginate(15)
        ->appends($request->query());

    return response()->json([
        'success' => true,

        'data' => \App\Http\Resources\InvoiceResource::collection(
            $invoices->items()
        ),

        'meta' => [
            'current_page' => $invoices->currentPage(),
            'last_page' => $invoices->lastPage(),
            'per_page' => $invoices->perPage(),
            'total' => $invoices->total(),
            'from' => $invoices->firstItem(),
            'to' => $invoices->lastItem(),
        ],

        'links' => [
            'first' => $invoices->url(1),
            'last' => $invoices->url($invoices->lastPage()),
            'prev' => $invoices->previousPageUrl(),
            'next' => $invoices->nextPageUrl(),
        ],

        'summary' => [
            'matching_invoice_count' => $matchingCount,
            'matching_invoice_total' => (float) $matchingTotal,

            // This remains the business-wide outstanding balance.
            'outstanding_invoice_total' =>
                $this->outstandingTotalForTenant($tenantId),
        ],
    ]);
}


    public function issue(Invoice $invoice,InvoiceService $invoiceService): JsonResponse 
    {
        abort_unless(
            $invoice->tenant_id === request()->user()->tenant_id,
            404
        );

        $invoice = $invoiceService->issue($invoice);

        return response()->json([
            'success' => true,
            'message' => 'Invoice issued successfully.',
            'data' => new \App\Http\Resources\InvoiceResource($invoice),
        ]);
    }

    public function cancel(Invoice $invoice,InvoiceService $invoiceService): JsonResponse 
    {
        abort_unless(
            $invoice->tenant_id === request()->user()->tenant_id,
            404
        );

        $invoice = $invoiceService->cancel($invoice);

        return response()->json([
            'success' => true,
            'message' => 'Invoice cancelled successfully.',
            'data' => new \App\Http\Resources\InvoiceResource($invoice),
        ]);
    }

    private function outstandingTotalForTenant(int $tenantId): float
    {
        $netPayments = DB::table('invoice_payments as ip')
            ->leftJoinSub(
                DB::table('invoice_payment_reversals')
                    ->select('invoice_payment_id')
                    ->selectRaw('SUM(amount) AS reversed_total')
                    ->where('tenant_id', $tenantId)
                    ->groupBy('invoice_payment_id'),
                'reversals',
                fn ($join) => $join->on(
                    'reversals.invoice_payment_id',
                    '=',
                    'ip.id'
                )
            )
            ->select('ip.invoice_id')
            ->selectRaw(
                'SUM(ip.amount - COALESCE(reversals.reversed_total, 0)) AS paid_total'
            )
            ->where('ip.tenant_id', $tenantId)
            ->groupBy('ip.invoice_id');

        $creditApplications = DB::table('credit_memo_applications')
            ->select('invoice_id')
            ->selectRaw('SUM(amount_applied) AS applied_total')
            ->where('tenant_id', $tenantId)
            ->groupBy('invoice_id');

        $outstandingExpression = '
            CASE
                WHEN invoices.total
                    - COALESCE(payments.paid_total, 0)
                    - COALESCE(credits.applied_total, 0) > 0
                THEN invoices.total
                    - COALESCE(payments.paid_total, 0)
                    - COALESCE(credits.applied_total, 0)
                ELSE 0
            END
        ';

        return (float) Invoice::query()
            ->where('invoices.tenant_id', $tenantId)
            ->whereNotIn('invoices.status', ['draft', 'cancelled'])
            ->leftJoinSub($netPayments, 'payments', fn ($join) =>
                $join->on('payments.invoice_id', '=', 'invoices.id')
            )
            ->leftJoinSub($creditApplications, 'credits', fn ($join) =>
                $join->on('credits.invoice_id', '=', 'invoices.id')
            )
            ->selectRaw(
                'COALESCE(SUM(' . $outstandingExpression . '), 0) AS outstanding'
            )
            ->value('outstanding');
    }
}