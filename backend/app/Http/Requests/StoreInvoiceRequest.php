<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'branch_id' => ['required', 'integer',  Rule::exists('branches', 'id')
            ->where(fn ($query) =>
                $query->where(
                    'tenant_id',
                    $this->user()->tenant_id
                )
            ),],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')
            ->where(fn ($query) =>
                $query->where(
                    'tenant_id',
                    $this->user()->tenant_id
                )
            ),],
            
            'order_id' => ['nullable', 'integer',  Rule::exists('orders', 'id')
            ->where(fn ($query) =>
                $query->where(
                    'tenant_id',
                    $this->user()->tenant_id
                )
            ),],

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
                Rule::exists('catalog_items', 'id')
                ->where(fn ($query) =>
                    $query->where(
                        'tenant_id',
                        $this->user()->tenant_id
                    )
                ),
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