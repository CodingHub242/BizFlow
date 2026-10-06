<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BusinessOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name' => [
                'required',
                'string',
                'max:255',
            ],

            'business_email' => [
                'required',
                'email',
                'max:255',
            ],

            'business_phone' => [
                'required',
                'string',
                'max:30',
            ],

            'business_type' => [
                'required',
                'string',
                'max:100',
            ],

            'owner_name' => [
                'required',
                'string',
                'max:255',
            ],

            'owner_email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ];
    }
}