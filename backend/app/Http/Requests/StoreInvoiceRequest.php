<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            //'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],

            'invoice_number' => [
                'required',
                'string',
                'max:255',
            ],

            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],

            'items.*.catalog_item_id' => [
                'required',
                'integer',
                'exists:catalog_items,id',
            ],

            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
            ],

            'items.*.unit_price' => [
                'required',
                'numeric',
                'gte:0',
            ],

            'items.*.discount' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'items.*.tax' => [
                'nullable',
                'numeric',
                'gte:0',
            ],
        ];
    }
}