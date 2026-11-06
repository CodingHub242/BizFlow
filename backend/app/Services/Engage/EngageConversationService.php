<?php

namespace App\Services\Engage;

use App\Models\EngageContact;
use App\Models\EngageConversation;
use App\Models\EngageMessage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EngageConversationService
{
    /**
     * Persist an inbound message and resolve its conversation.
     *
     * Duplicate provider messages are returned without creating
     * another message or incrementing unread count.
     */
    public function receiveInboundMessage(
        int $tenantId,
        EngageContact $contact,
        array $payload
    ): EngageMessage {
        if ($contact->tenant_id !== $tenantId) {
            throw new InvalidArgumentException(
                'Contact does not belong to the specified tenant.'
            );
        }

        if (!$contact->exists || $contact->trashed()) {
            throw new InvalidArgumentException(
                'A valid active Engage contact is required.'
            );
        }

        if (empty($payload['body']) && empty($payload['media'])) {
            throw new InvalidArgumentException(
                'An inbound message must contain text or media.'
            );
        }

        $channel = $payload['channel'] ?? 'whatsapp';
        $providerMessageId = $payload['provider_message_id'] ?? null;

        return DB::transaction(function () use (
            $tenantId,
            $contact,
            $payload,
            $channel,
            $providerMessageId
        ) {
            // Check idempotency before creating or updating a conversation.
            if ($providerMessageId) {
                $existingMessage = EngageMessage::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider_message_id', $providerMessageId)
                    ->first();

                if ($existingMessage) {
                    return $existingMessage;
                }
            }

            $conversation = EngageConversation::query()
                ->where('tenant_id', $tenantId)
                ->where('contact_id', $contact->id)
                ->where('channel', $channel)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (!$conversation) {
                $conversation = EngageConversation::create([
                    'tenant_id' => $tenantId,
                    'contact_id' => $contact->id,
                    'channel' => $channel,
                    'status' => 'open',
                    'unread_count' => 0,
                ]);
            }

            $receivedAt = $payload['received_at'] ?? now();

            $message = EngageMessage::create([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->id,
                'provider_message_id' => $providerMessageId,
                'direction' => 'inbound',
                'message_type' => $payload['message_type'] ?? 'text',
                'body' => $payload['body'] ?? null,
                'status' => 'received',
                'media' => $payload['media'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
            ]);

            $conversation->increment('unread_count');

            $conversation->update([
                'last_message_at' => $receivedAt,
                'last_inbound_at' => $receivedAt,
            ]);

            return $message->fresh();
        });
    }
}
