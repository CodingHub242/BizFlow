<?php

namespace App\Services\Engage;

use App\Models\EngageConversation;
use App\Models\EngageMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EngageMessageService
{
    /**
     * Persist an outbound message for a tenant conversation.
     *
     * This method does not send the message to an external provider.
     */
    public function queueOutboundMessage(
        int $tenantId,
        int $conversationId,
        string $body
    ): EngageMessage {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => ['A message cannot be empty.'],
            ]);
        }

        return DB::transaction(function () use (
            $tenantId,
            $conversationId,
            $body
        ) {
            $conversation = EngageConversation::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($conversationId);

            $message = $conversation->messages()->create([
                'tenant_id' => $tenantId,
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => $body,
                'status' => 'queued',
                'media' => null,
                'metadata' => null,
                'sent_at' => null,
                'delivered_at' => null,
                'read_at' => null,
            ]);

            $conversation->update([
                'last_message_at' => now(),
            ]);

            return $message;
        });
    }
}
