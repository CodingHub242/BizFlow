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
        $invoices = Invoice::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with([
                'items',
                'customer',
                'branch',
                'payments.reversal',
                'creditMemoApplications',
            ])
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\InvoiceResource::collection($invoices),
            'summary' => [
                'outstanding_invoice_total' =>
                    $this->outstandingTotalForTenant($request->user()->tenant_id),
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