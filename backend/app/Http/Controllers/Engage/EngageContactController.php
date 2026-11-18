<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use App\Http\Resources\EngageContactResource;
use App\Models\EngageContact;
use App\Http\Requests\Engage\UpdateEngageContactRequest;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\StoreCustomerRequest;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use App\Services\Engage\EngageContactService;
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
    UpdateEngageContactRequest $request,
    int $contact
): JsonResponse {

    $tenantId = $this->authorizeEngage(
        $request,
        'engage.contacts.manage'
    );

    $validated = $request->validated();

    $updatedContact = DB::transaction(function () use (
        $tenantId,
        $contact,
        $validated
    ) {
        $engageContact = EngageContact::query()
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->findOrFail($contact);

        // Normalize the phone number whenever it is supplied.
        if (array_key_exists('phone', $validated)) {

            $phone = trim((string) ($validated['phone'] ?? ''));

            if ($phone === '') {
                $validated['phone'] = null;
                $validated['normalized_phone'] = null;
            } else {
                $contactService = app(EngageContactService::class);

                $normalizedPhone = $contactService
                    ->normalizeGhanaPhone($phone);

                if (!$normalizedPhone) {
                    abort(
                        422,
                        'Please provide a valid Ghanaian phone number.'
                    );
                }

                $validated['phone'] = $phone;
                $validated['normalized_phone'] = $normalizedPhone;
            }
        }

        $engageContact->fill($validated);
        $engageContact->save();

        return $engageContact->fresh()->load('customer');
    });

    return response()->json([
        'success' => true,
        'message' => 'Contact updated successfully.',
        'data' => new EngageContactResource($updatedContact),
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
): JsonResponse {
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

}
