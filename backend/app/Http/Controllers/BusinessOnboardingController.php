<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessOnboardingRequest;
use App\Services\Onboarding\BusinessOnboardingService;
use Illuminate\Http\JsonResponse;

class BusinessOnboardingController extends Controller
{
    public function __construct(
        private readonly BusinessOnboardingService $onboardingService,
    ) {
    }

    public function register(BusinessOnboardingRequest $request): JsonResponse 
    {
        $result = $this->onboardingService->registerBusiness(
            [
                'name' => $request->string('business_name')->toString(),
                'email' => $request->string('business_email')->toString(),
                'phone' => $request->string('business_phone')->toString(),
                'business_type' => $request->string('business_type')->toString(),
            ],
            [
                'name' => $request->string('owner_name')->toString(),
                'email' => $request->string('owner_email')->toString(),
                'password' => $request->string('password')->toString(),
            ],
        );

        return response()->json([
            'message' => 'Business registration submitted successfully. Your account is awaiting approval.',
            'data' => [
                'tenant' => [
                    'id' => $result['tenant']->id,
                    'name' => $result['tenant']->name,
                    'status' => $result['tenant']->status->value,
                ],
                'owner' => [
                    'id' => $result['owner']->id,
                    'name' => $result['owner']->name,
                    'email' => $result['owner']->email,
                ],
            ],
        ], 201);
    }
}