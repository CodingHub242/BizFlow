<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use App\Http\Resources\EngageContactResource;
use App\Models\EngageContact;
use App\Http\Requests\Engage\UpdateEngageContactRequest;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\StoreCustomerRequest;
use App\Models\Customer;
use App\Services\Engage\EngageContactService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class EngageContactController extends Controller
{
    private function authorizeEngage(
        Request $request,
        string $permission
    ): int {
        $user = $request->user();

        abort_unless(
            $user && $user->tenant_id,
            403,
            'Business account could not be found.'
        );

        $tenantId = (int) $user->tenant_id;

        setPermissionsTeamId($tenantId);

        abort_unless(
            $user->hasPermissionTo($permission, 'web'),
            403,
            'You do not have permission to perform this action.'
        );

        return $tenantId;
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->authorizeEngage(
            $request,
            'engage.contacts.view'
        );

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,inactive,archived'],
            'lifecycle_status' => [
                'sometimes',
                'string',
                'in:prospect,lead,customer',
            ],
            'source' => ['sometimes', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = EngageContact::query()
            ->where('tenant_id', $tenantId)
            ->with(['customer']);

        if ($request->filled('search')) {
            $search = trim($validated['search'] ?? '');

            if ($search !== '') {
                $term = '%' . $search . '%';

                $query->where(function ($subQuery) use ($term) {
                    $subQuery->where('name', 'ILIKE', $term)
                        ->orWhere('phone', 'ILIKE', $term)
                        ->orWhere('normalized_phone', 'ILIKE', $term)
                        ->orWhere('email', 'ILIKE', $term)
                        ->orWhere('company_name', 'ILIKE', $term);
                });
            }
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['lifecycle_status'])) {
            $query->where(
                'lifecycle_status',
                $validated['lifecycle_status']
            );
        }

        if (!empty($validated['source'])) {
            $query->where('source', $validated['source']);
        }

        $perPage = $validated['per_page'] ?? 15;

        $contacts = $query
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => EngageContactResource::collection(
                $contacts->items()
            ),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'per_page' => $contacts->perPage(),
                'total' => $contacts->total(),
                'last_page' => $contacts->lastPage(),
            ],
        ]);
    }

    public function show(
        Request $request,
        int $contact
    ): JsonResponse {
        $tenantId = $this->authorizeEngage(
            $request,
            'engage.contacts.view'
        );

        $engageContact = EngageContact::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'customer',
                'conversations.latestMessage',
            ])
            ->findOrFail($contact);

        return response()->json([
            'success' => true,
            'data' => new EngageContactResource($engageContact),
        ]);
    }

  
public function update(
    Request $request,
    int $contact
): JsonResponse {

    $tenantId = $this->authorizeEngage(
        $request,
        'engage.contacts.manage'
    );

    $allowedFields = [
        'name',
        'phone',
        'email',
        'company_name',
        'source',
        'lifecycle_status',
        'status',
        'notes',
    ];

    $data = $request->only($allowedFields);

    $errors = [];

	if (isset($data['name']) && (
	    !is_string($data['name']) ||
	    mb_strlen($data['name']) > 255
	)) {
	    $errors['name'] = 'Name must be a string of no more than 255 characters.';
	}

	if (isset($data['email']) && $data['email'] !== '') {
	    if (!is_string($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
	        $errors['email'] = 'Please provide a valid email address.';
	    } elseif (mb_strlen($data['email']) > 255) {
	        $errors['email'] = 'Email cannot exceed 255 characters.';
	    }
	}

	if (isset($data['phone']) && $data['phone'] !== '') {
	    if (!is_string($data['phone']) || mb_strlen($data['phone']) > 50) {
	        $errors['phone'] = 'Phone must not exceed 50 characters.';
	    }
	}

	if (isset($data['company_name']) && (
	    !is_string($data['company_name']) ||
	    mb_strlen($data['company_name']) > 255
	)) {
	    $errors['company_name'] = 'Company name cannot exceed 255 characters.';
	}

	if (isset($data['source']) && (
	    !is_string($data['source']) ||
	    mb_strlen($data['source']) > 50
	)) {
	    $errors['source'] = 'Source cannot exceed 50 characters.';
	}

	if (isset($data['lifecycle_status']) &&
		!in_array($data['lifecycle_status'], ['prospect', 'lead', 'customer'], true)
	) {
		$errors['lifecycle_status'] = 'Invalid lifecycle status.';
	}
	
	if (isset($data['status']) &&
		!in_array($data['status'], ['active', 'inactive', 'archived'], true)
	) {
		$errors['status'] = 'Invalid contact status.';
	}
	
	if (isset($data['notes']) && !is_string($data['notes'])) {
		$errors['notes'] = 'Notes must be text.';
	}
	
	if (!empty($errors)) {
		return response()->json([
			'success' => false,
			'message' => 'Please correct the supplied information.',
			'errors' => $errors,
		], 422);
	}

    $engageContact = EngageContact::query()
        ->where('tenant_id', $tenantId)
        ->findOrFail($contact);

    DB::transaction(function () use ($engageContact, $data) {
        $engageContact->fill($data);
        $engageContact->save();
    });

    $engageContact->refresh()->load('customer');

    return response()->json([
        'success' => true,
        'message' => 'Contact updated successfully.',
        'data' => new EngageContactResource($engageContact),
    ]);
}


    public function linkCustomer(
        Request $request,
        int $contact
    ): JsonResponse {
        $tenantId = $this->authorizeEngage(
            $request,
            'engage.contacts.manage'
        );

        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'min:1'],
        ]);

        $result = DB::transaction(function () use (
            $tenantId,
            $contact,
            $validated
        ) {
            $engageContact = EngageContact::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($contact);

            $customer = Customer::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($validated['customer_id']);

            // Prevent silently replacing an existing customer link.
            if (
                $engageContact->customer_id !== null &&
                (int) $engageContact->customer_id !== (int) $customer->id
            ) {
                abort(409, 'This contact is already linked to another Core customer.');
            }

            $contactService = app(EngageContactService::class);

            $contactPhone = $engageContact->normalized_phone
                ?: ($engageContact->phone
                    ? $contactService->normalizeGhanaPhone($engageContact->phone)
                    : null);

            $customerPhone = $customer->phone
                ? $contactService->normalizeGhanaPhone($customer->phone)
                : null;

            $phoneMismatch = (
                $contactPhone !== null &&
                $customerPhone !== null &&
                $contactPhone !== $customerPhone
            );

            $engageContact->customer_id = $customer->id;
            $engageContact->lifecycle_status = 'customer';
            $engageContact->save();

            return [
                'contact' => $engageContact->fresh()->load('customer'),
                'phone_mismatch' => $phoneMismatch,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Contact linked to Core customer successfully.',
            'warning' => $result['phone_mismatch']
                ? 'The contact and Core customer have different phone numbers. Please verify that this is the correct customer.'
                : null,
            'data' => new EngageContactResource($result['contact']),
        ]);
    }

    public function convertToCustomer(
	    StoreCustomerRequest $request,
	    int $contact
	): JsonResponse 
	{
    $tenantId = $this->authorizeEngage(
        $request,
        'engage.contacts.manage'
    );

    $data = $request->validated();

    $result = DB::transaction(function () use (
        $tenantId,
        $contact,
        $data
    ) {
        $engageContact = EngageContact::query()
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->findOrFail($contact);

        if ($engageContact->customer_id !== null) {
            abort(
                409,
                'This Engage contact is already linked to a Core customer.'
            );
        }

        // Use submitted values, falling back to the Engage contact.
        $customerData = $data;

        $customerData['phone'] = $data['phone']
            ?? $engageContact->phone;

        $customerData['email'] = $data['email']
            ?? $engageContact->email;

        $customerData['company_name'] = $data['company_name']
            ?? $engageContact->company_name;

        $customerData['notes'] = $data['notes']
            ?? $engageContact->notes;

        $contactService = app(EngageContactService::class);

        // Check for an existing Core customer before creating one.
        $normalizedPhone = $customerData['phone']
            ? $contactService->normalizeGhanaPhone(
                $customerData['phone']
            )
            : null;

        if ($customerData['phone'] && !$normalizedPhone) {
            abort(
                422,
                'Please provide a valid Ghanaian phone number.'
            );
        }

        if ($normalizedPhone) {
            $matches = $contactService->findCoreCustomersByPhone(
                $tenantId,
                $normalizedPhone
            );

            if ($matches->count() === 1) {
                return [
                    'type' => 'existing_customer',
                    'customer' => $matches->first(),
                ];
            }

            if ($matches->count() > 1) {
                return [
                    'type' => 'multiple_matches',
                    'customers' => $matches,
                ];
            }
        }

        // Create the Core customer.
        $customer = Customer::create(array_merge(
            $customerData,
            [
                'tenant_id' => $tenantId,
                'status' => 'active',
            ]
        ));

        // Link the Engage contact to the new Core customer.
        $engageContact->customer_id = $customer->id;
        $engageContact->lifecycle_status = 'customer';
        $engageContact->save();

        return [
            'type' => 'created',
            'customer' => $customer,
            'contact' => $engageContact->fresh()->load('customer'),
        ];
    });

    if ($result['type'] === 'existing_customer') {
        return response()->json([
            'success' => false,
            'message' => 'A Core customer with this phone number already exists. Link the existing customer instead.',
            'customer' => new \App\Http\Resources\CustomerResource(
                $result['customer']
            ),
        ], 409);
    }

    if ($result['type'] === 'multiple_matches') {
        return response()->json([
            'success' => false,
            'message' => 'Multiple Core customers have this phone number. Please identify the correct customer and link it manually.',
            'matching_customers' =>
                \App\Http\Resources\CustomerResource::collection(
                    $result['customers']
                ),
        ], 409);
    }

    return response()->json([
        'success' => true,
        'message' => 'Prospect successfully converted to a Core customer.',
        'data' => [
            'contact' => new EngageContactResource(
                $result['contact']
            ),
            'customer' => new \App\Http\Resources\CustomerResource(
                $result['customer']
            ),
        ],
    ], 201);
}

	public function store(Request $request): JsonResponse
	{
	    $tenantId = $this->authorizeEngage(
	        $request,
	        'engage.contacts.manage'
	    );
	
	    $validated = $request->validate([
	        'name' => ['required', 'string', 'max:255'],
	        'phone' => ['nullable', 'string', 'max:50'],
	        'email' => ['nullable', 'email', 'max:255'],
	        'company_name' => ['nullable', 'string', 'max:255'],
	        'source' => ['required', 'string', 'max:50'],
	        'lifecycle_status' => [
	            'required',
	            'in:prospect,lead',
	        ],
	        'notes' => ['nullable', 'string'],
	    ]);
	
	    $normalizedPhone = null;
	
	    if (!empty($validated['phone'])) {
	        $normalizedPhone = app(EngageContactService::class)
	            ->normalizeGhanaPhone($validated['phone']);
	
	        if (!$normalizedPhone) {
	            return response()->json([
	                'success' => false,
	                'message' => 'Please provide a valid Ghanaian phone number.',
	                'errors' => [
	                    'phone' => [
	                        'Please provide a valid Ghanaian phone number.',
	                    ],
	                ],
	            ], 422);
	        }
	    }
	
	    if ($normalizedPhone) {
	        $duplicate = EngageContact::query()
	            ->where('tenant_id', $tenantId)
	            ->where('normalized_phone', $normalizedPhone)
	            ->exists();
	
	        if ($duplicate) {
	            return response()->json([
	                'success' => false,
	                'message' => 'An Engage contact with this phone number already exists.',
	                'errors' => [
	                    'phone' => [
	                        'This phone number is already registered as an Engage contact.',
	                    ],
	                ],
	            ], 422);
	        }
	    }
	
	    $contact = EngageContact::create([
	        'tenant_id' => $tenantId,
	        'customer_id' => null,
	        'name' => $validated['name'],
	        'phone' => $validated['phone'] ?? null,
	        'normalized_phone' => $normalizedPhone,
	        'email' => $validated['email'] ?? null,
	        'company_name' => $validated['company_name'] ?? null,
	        'source' => $validated['source'],
	        'lifecycle_status' => $validated['lifecycle_status'],
	        'status' => 'active',
	        'notes' => $validated['notes'] ?? null,
	    ]);
	
	    return response()->json([
	        'success' => true,
	        'message' => 'Engage contact created successfully.',
	        'data' => new EngageContactResource($contact),
	    ], 201);
	}

}
