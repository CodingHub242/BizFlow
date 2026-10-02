<?php

namespace App\Http\Requests;

use App\ExpensePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')
                    ->where(fn ($query) =>
                        $query->where(
                            'tenant_id',
                            $this->user()->tenant_id
                        )
                    ),
            ],

            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')
                    ->where(fn ($query) =>
                        $query->where(
                            'tenant_id',
                            $this->user()->tenant_id
                        )
                    ),
            ],

            'amount' => [
                'required',
                'numeric',
                'gt:0',
            ],

            'payment_method' => [
                'required',
                Rule::enum(ExpensePaymentMethod::class),
            ],

            'reference' => [
                'nullable',
                'string',
                'max:255',
            ],

            'expense_date' => [
                'required',
                'date',
            ],

            'description' => [
                'required',
                'string',
                'max:255',
            ],

            'notes' => [
                'nullable',
                'string',
            ],
        ];
    }
}