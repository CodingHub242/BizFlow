<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use App\Models\EngageConversation;
use Illuminate\Http\Request;
use App\Services\Engage\EngageMessageService;
use App\Http\Resources\EngageConversationResource;
use App\Http\Resources\EngageMessageResource;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Builder;

class EngageConversationController extends Controller
{
    /**
     * List tenant conversations.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        abort_unless($user && $user->tenant_id, 403);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:open,closed,pending'],
            'channel' => ['nullable', 'string', 'max:50'],
            'assigned_to' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = EngageConversation::query()
            ->where('tenant_id', $user->tenant_id)
            ->with([
                'contact:id,tenant_id,customer_id,name,phone,email,company_name,lifecycle_status',
                'contact.customer:id,name,phone,email',
                'assignee:id,name',
                'latestMessage'
            ])
            ->withMax('messages', 'created_at')
            ->withCount('messages');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['channel'])) {
            $query->where('channel', $validated['channel']);
        }

        if (!empty($validated['assigned_to'])) {
            $query->where('assigned_to', $validated['assigned_to']);
        }

        if (!empty($validated['search'])) {
            $search = trim($validated['search']);

            $query->whereHas('contact', function (Builder $contactQuery) use ($search) {
                $contactQuery->where(function (Builder $searchQuery) use ($search) {
                    $searchQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%");
                });
            });
        }

        $conversations = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return EngageConversationResource::collection($conversations);
    }

    /**
     * Show one conversation.
     */
    public function show(Request $request, int $conversation)
    {
        $user = $request->user();

        abort_unless($user && $user->tenant_id, 403);

        $conversation = EngageConversation::query()
            ->where('tenant_id', $user->tenant_id)
            ->with([
                'contact.customer',
                'assignee:id,name',
                'contact.customer',
                'assignee',
                'latestMessage',
            ])
            ->findOrFail($conversation);

       return new EngageConversationResource($conversation);
    }

    /**
     * Retrieve paginated conversation messages.
     */
    public function messages(Request $request, int $conversation)
    {
        $user = $request->user();

        abort_unless($user && $user->tenant_id, 403);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'before_id' => ['nullable', 'integer'],
        ]);

        $conversation = EngageConversation::query()
            ->where('tenant_id', $user->tenant_id)
            ->findOrFail($conversation);

        $messagesQuery = $conversation->messages()
            ->where('tenant_id', $user->tenant_id);

        if (!empty($validated['before_id'])) {
            $messagesQuery->where('id', '<', $validated['before_id']);
        }

        $messages = $messagesQuery
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50)
            ->withQueryString();

       return EngageMessageResource::collection($messages);
    }

    /**
     * Mark a conversation as read.
     */
    public function markAsRead(Request $request, int $conversation)
    {
        $user = $request->user();

        abort_unless($user && $user->tenant_id, 403);

        $conversation = EngageConversation::query()
            ->where('tenant_id', $user->tenant_id)
            ->findOrFail($conversation);

        $conversation->update([
            'unread_count' => 0,
        ]);

        $conversation->load([
            'contact.customer',
            'assignee',
            'latestMessage',
        ]);

        return new EngageConversationResource($conversation);
    }


/**
 * Store an outbound conversation message.
 */
    public function storeMessage(
        Request $request,
        int $conversation,
        EngageMessageService $messageService
    ) {
        $user = $request->user();

        abort_unless($user && $user->tenant_id, 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $message = $messageService->queueOutboundMessage(
            tenantId: (int) $user->tenant_id,
            conversationId: $conversation,
            body: $validated['body'],
        );

       return response()->json([
            'message' => 'Message queued successfully.',
            'data' => new EngageMessageResource($message),
        ], 201);
    }

}
