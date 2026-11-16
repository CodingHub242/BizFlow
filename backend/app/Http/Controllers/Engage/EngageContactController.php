<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use App\Http\Resources\EngageContactResource;
use App\Models\EngageContact;
use Illuminate\Http\JsonResponse;
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

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $term = '%' . $search . '%';

            $query->where(function ($subQuery) use ($term) {
                $subQuery->where('name', 'ILIKE', $term)
                    ->orWhere('phone', 'ILIKE', $term)
                    ->orWhere('normalized_phone', 'ILIKE', $term)
                    ->orWhere('email', 'ILIKE', $term)
                    ->orWhere('company_name', 'ILIKE', $term);
            });
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
}
