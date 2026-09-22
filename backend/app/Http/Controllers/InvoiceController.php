<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\StoreInvoicePaymentRequest;
use App\Models\Invoice;
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
            ])
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\InvoiceResource::collection($invoices),
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
}