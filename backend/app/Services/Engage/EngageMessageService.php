<?php

namespace App\Services\Engage;

use App\Models\EngageConversation;
use App\Models\EngageMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use App\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;

class EngageMessageService
{
    /**
     * Persist and send an outbound WhatsApp text message.
     *
     * The database transaction is completed before calling Meta.
     * This avoids holding database locks during an external request.
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

        // Retrieve and validate the tenant-owned conversation.
        $conversation = EngageConversation::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'contact',
                'whatsappNumber.account',
            ])
            ->findOrFail($conversationId);

        $number = $conversation->whatsappNumber;
        $account = $number?->account;

        if (!$number || !$account) {
            throw ValidationException::withMessages([
                'conversation' => [
                    'This conversation does not have a connected WhatsApp number.',
                ],
            ]);
        }

        // Verify tenant ownership across the related records.
        if (
            (int) $number->tenant_id !== $tenantId ||
            (int) $account->tenant_id !== $tenantId
        ) {
            throw ValidationException::withMessages([
                'conversation' => [
                    'The WhatsApp account does not belong to this tenant.',
                ],
            ]);
        }

        if ($number->status !== 'active' || $account->status !== 'active') {
            throw ValidationException::withMessages([
                'conversation' => [
                    'The WhatsApp account or phone number is not active.',
                ],
            ]);
        }

        if (!$account->access_token) {
            throw ValidationException::withMessages([
                'conversation' => [
                    'The WhatsApp account has no access token. Reconnect the account.',
                ],
            ]);
        }

        // Free-form text messages require an open 24-hour service window.
        $lastInboundAt = $conversation->last_inbound_at;

        if (
            !$lastInboundAt ||
            $lastInboundAt->lt(now()->subHours(24))
        ) {
            throw ValidationException::withMessages([
                'body' => [
                    'The 24-hour customer service window has expired. '
                    . 'Use an approved WhatsApp message template instead.',
                ],
            ]);
        }

        // Prefer the normalized contact number where available.
        $recipient = $conversation->contact?->normalized_phone
            ?: $conversation->contact?->phone;

        $recipient = preg_replace('/\D+/', '', (string) $recipient);

        if (
            !$recipient ||
            strlen($recipient) < 8 ||
            strlen($recipient) > 15
        ) {
            throw ValidationException::withMessages([
                'conversation' => [
                    'The contact does not have a valid WhatsApp phone number.',
                ],
            ]);
        }

        if (!$number->phone_number_id) {
            throw ValidationException::withMessages([
                'conversation' => [
                    'The connected WhatsApp number has no Phone Number ID.',
                ],
            ]);
        }

        // Save the message before making the external API request.
        $message = DB::transaction(function () use (
            $tenantId,
            $conversationId,
            $body
        ) {
            $lockedConversation = EngageConversation::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($conversationId);

            $message = $lockedConversation->messages()->create([
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
				'sender_type' => 'human',
				'handling_version' => null,
				'dispatched_at' => null,
            ]);

            $lockedConversation->update([
                'last_message_at' => now(),
            ]);

            return $message;
        });

        $version = config('services.whatsapp.graph_api_version');

        if (!$version) {
            return $this->markFailed(
                $message,
                'WhatsApp Graph API version is not configured.'
            );
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $version,
            $number->phone_number_id
        );

        try {
            $response = Http::withToken($account->access_token)
                ->acceptJson()
                ->timeout(20)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipient,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $body,
                    ],
                ]);

            if (!$response->successful()) {
                $errorMessage = $response->json('error.message')
                    ?: 'Meta rejected the WhatsApp message.';

                return $this->markFailed(
                    $message,
                    $errorMessage,
                    [
                        'http_status' => $response->status(),
                        'provider_error_code' => $response->json('error.code'),
                    ]
                );
            }

            $providerMessageId = $response->json('messages.0.id');

            if (!$providerMessageId) {
                return $this->markFailed(
                    $message,
                    'Meta accepted the request but returned no message ID.',
                    [
                        'http_status' => $response->status(),
                    ]
                );
            }

            $message->update([
                'provider_message_id' => $providerMessageId,
                'status' => 'sent',
                'sent_at' => now(),
                'metadata' => [
                    'provider' => 'whatsapp',
                    'messaging_product' => 'whatsapp',
                ],
            ]);

            return $message->fresh();

        } catch (ConnectionException $exception) {
            // A timeout may happen after Meta has accepted the message.
            // Do not automatically resend: that could create duplicates.
            return $this->markFailed(
                $message,
                'Could not confirm the WhatsApp API response. '
                . 'The delivery outcome may be uncertain.',
                [
                    'delivery_outcome_uncertain' => true,
                ]
            );
        }
    }

    /**
     * Persist a failed outbound message without exposing credentials.
     */
    private function markFailed(
        EngageMessage $message,
        string $reason,
        array $details = []
    ): EngageMessage {
        $message->update([
            'status' => 'failed',
            'metadata' => array_merge([
                'provider' => 'whatsapp',
                'error' => $reason,
            ], $details),
        ]);

        return $message->fresh();
    }

	
	/**
	 * Prepare an outbound BeezBud message.
	 *
	 * This method does not contact Meta.
	 * Dispatch is handled separately after revalidating ownership.
	 */
	public function prepareOutboundAiMessage(
	    int $tenantId,
	    int $conversationId,
	    string $body,
	    int $expectedHandlingVersion
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
	        $body,
	        $expectedHandlingVersion
	    ) {
	        // Confirm the tenant exists and BeezBud is enabled.
	        $tenant = Tenant::query()->findOrFail($tenantId);
	
	        if (!$tenant->isBeezBudEnabled()) {
	            throw ValidationException::withMessages([
	                'beezbud' => [
	                    'BeezBud is disabled for this tenant.',
	                ],
	            ]);
	        }
	
	        // Lock the conversation before validating ownership.
	        $conversation = EngageConversation::query()
	            ->where('tenant_id', $tenantId)
	            ->lockForUpdate()
	            ->findOrFail($conversationId);
	
	        if (
	            $conversation->handling_mode !==
	            EngageConversation::HANDLING_AI
	        ) {
	            throw ValidationException::withMessages([
	                'conversation' => [
	                    'BeezBud cannot respond because this conversation is handled by a human.',
	                ],
	            ]);
	        }
	
	        if (
	            (int) $conversation->handling_version !==
	            $expectedHandlingVersion
	        ) {
	            throw ValidationException::withMessages([
	                'conversation' => [
	                    'This AI response is stale and can no longer be used.',
	                ],
	            ]);
	        }
	
	        return $conversation->messages()->create([
	            'tenant_id' => $tenantId,
	            'direction' => 'outbound',
	            'message_type' => 'text',
	            'body' => $body,
	            'status' => 'queued',
	            'sender_type' => 'ai',
	            'handling_version' => $expectedHandlingVersion,
	            'dispatched_at' => null,
	            'media' => null,
	            'metadata' => null,
	            'sent_at' => null,
	            'delivered_at' => null,
	            'read_at' => null,
	        ]);
	    });
	}

    public function prepareAndCompleteInboundAiMessage(
        int $tenantId,
        int $conversationId,
        int $inboundMessageId,
        string $body,
        string $processingToken,
        int $expectedHandlingVersion
    ): ?EngageMessage {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => ['A message cannot be empty.'],
            ]);
        }

        return DB::transaction(function () use (
            $tenantId,
            $conversationId,
            $inboundMessageId,
            $body,
            $processingToken,
            $expectedHandlingVersion
        ) {
            $tenant = Tenant::query()->findOrFail($tenantId);

            $conversation = EngageConversation::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($conversationId);

            $inbound = EngageMessage::query()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->lockForUpdate()
                ->findOrFail($inboundMessageId);

            // The worker may have lost its claim to another worker.
            if (
                $inbound->ai_processing_status !== 'processing' ||
                $inbound->ai_processing_token !== $processingToken
            ) {
                return null;
            }

            // Revalidate all ownership and eligibility conditions.
            if (
                !$tenant->isBeezBudEnabled() ||
                $conversation->handling_mode !== EngageConversation::HANDLING_AI ||
                (int) $conversation->handling_version !== $expectedHandlingVersion ||
                (int) $inbound->handling_version !== $expectedHandlingVersion
            ) {
                throw ValidationException::withMessages([
                    'conversation' => [
                        'BeezBud can no longer respond to this conversation.',
                    ],
                ]);
            }

            if (
                $inbound->direction !== 'inbound' ||
                $inbound->message_type !== 'text'
            ) {
                throw ValidationException::withMessages([
                    'message' => [
                        'Only inbound text messages can receive AI responses.',
                    ],
                ]);
            }

            // Create the outbound response.
            $response = $conversation->messages()->create([
                'tenant_id' => $tenantId,
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => $body,
                'status' => 'queued',
                'sender_type' => 'ai',
                'handling_version' => $expectedHandlingVersion,
                'dispatched_at' => null,
                'media' => null,
                'metadata' => null,
                'sent_at' => null,
                'delivered_at' => null,
                'read_at' => null,
            ]);

            // Complete and link the inbound message in the same transaction.
            $inbound->update([
                'ai_processing_status' => 'completed',
                'ai_response_message_id' => $response->id,
                'ai_processing_token' => null,
            ]);

            return $response->fresh();
        }, 3);
    }

	
	/**
	 * Dispatch a prepared BeezBud message to WhatsApp.
	 *
	 * Ownership and tenant feature status are revalidated immediately
	 * before dispatch. A message is never automatically retried after
	 * it crosses the dispatch boundary.
	 */
	public function dispatchOutboundAiMessage(
	    int $tenantId,
	    int $conversationId,
	    int $messageId
	): EngageMessage {
	    // Lock the conversation and message together before deciding
	    // whether this AI response is still allowed to be sent.
	    $message = DB::transaction(function () use (
	        $tenantId,
	        $conversationId,
	        $messageId
	    ) {
	        $tenant = Tenant::query()->findOrFail($tenantId);
	
	        $conversation = EngageConversation::query()
	            ->where('tenant_id', $tenantId)
	            ->lockForUpdate()
	            ->findOrFail($conversationId);
	
	        $message = EngageMessage::query()
	            ->where('tenant_id', $tenantId)
	            ->where('conversation_id', $conversationId)
	            ->lockForUpdate()
	            ->findOrFail($messageId);
	
	        // Only prepared outbound AI messages may be dispatched.
	        if (
	            $message->direction !== 'outbound' ||
	            $message->sender_type !== 'ai'
	        ) {
	            throw ValidationException::withMessages([
	                'message' => [
	                    'Only prepared BeezBud messages can be dispatched.',
	                ],
	            ]);
	        }
	
	        // A message that has already left the queued state must
	        // never be sent again.
	        if ($message->status !== 'queued') {
	            return $message;
	        }
	
	        $cancellationReason = null;
	
	        if (!$tenant->isBeezBudEnabled()) {
	            $cancellationReason = 'beezbud_disabled';
	        } elseif (
	            $conversation->handling_mode !==
	            EngageConversation::HANDLING_AI
	        ) {
	            $cancellationReason = 'conversation_human_owned';
	        } elseif (
	            (int) $conversation->handling_version !==
	            (int) $message->handling_version
	        ) {
	            $cancellationReason = 'stale_handling_version';
	        }
	
	        if ($cancellationReason !== null) {
	            $message->update([
	                'status' => 'cancelled',
	                'metadata' => [
	                    'provider' => 'whatsapp',
	                    'cancellation_reason' => $cancellationReason,
	                ],
	            ]);
	
	            return $message->fresh();
	        }
	
	        // Mark the message as dispatching before contacting Meta.
	        // This is the no-automatic-retry boundary.
	        $message->update([
	            'status' => 'dispatching',
	            'dispatched_at' => now(),
	        ]);
	
	        return $message->fresh();
	    });
	
	    // Already sent, cancelled, failed, or currently dispatching:
	    // never make another provider request.
	    if ($message->status !== 'dispatching') {
	        return $message;
	    }
	
	    // Load the WhatsApp destination and credentials after the
	    // transaction has released its database locks.
	    $conversation = EngageConversation::query()
	        ->where('tenant_id', $tenantId)
	        ->with([
	            'contact',
	            'whatsappNumber.account',
	        ])
	        ->findOrFail($conversationId);
	
	    $number = $conversation->whatsappNumber;
	    $account = $number?->account;
	
	    if (!$number || !$account) {
	        return $this->markFailed(
	            $message,
	            'This conversation does not have a connected WhatsApp number.'
	        );
	    }
	
	    if (
	        (int) $number->tenant_id !== $tenantId ||
	        (int) $account->tenant_id !== $tenantId
	    ) {
	        return $this->markFailed(
	            $message,
	            'The WhatsApp account does not belong to this tenant.'
	        );
	    }
	
	    if ($number->status !== 'active' || $account->status !== 'active') {
	        return $this->markFailed(
	            $message,
	            'The WhatsApp account or phone number is not active.'
	        );
	    }
	
	    if (!$account->access_token) {
	        return $this->markFailed(
	            $message,
	            'The WhatsApp account has no access token.'
	        );
	    }
	
	    if (
	        !$conversation->last_inbound_at ||
	        $conversation->last_inbound_at->lt(now()->subHours(24))
	    ) {
	        return $this->markFailed(
	            $message,
	            'The 24-hour customer service window has expired.'
	        );
	    }
	
	    $recipient = $conversation->contact?->normalized_phone
	        ?: $conversation->contact?->phone;
	
	    $recipient = preg_replace('/\D+/', '', (string) $recipient);
	
	    if (
	        !$recipient ||
	        strlen($recipient) < 8 ||
	        strlen($recipient) > 15
	    ) {
	        return $this->markFailed(
	            $message,
	            'The contact does not have a valid WhatsApp phone number.'
	        );
	    }
	
	    if (!$number->phone_number_id) {
	        return $this->markFailed(
	            $message,
	            'The connected WhatsApp number has no Phone Number ID.'
	        );
	    }
	
	    $version = config('services.whatsapp.graph_api_version');
	
	    if (!$version) {
	        return $this->markFailed(
	            $message,
	            'WhatsApp Graph API version is not configured.'
	        );
	    }
	
	    $url = sprintf(
	        'https://graph.facebook.com/%s/%s/messages',
	        $version,
	        $number->phone_number_id
	    );
	
	    try {
	        $response = Http::withToken($account->access_token)
	            ->acceptJson()
	            ->timeout(20)
	            ->post($url, [
	                'messaging_product' => 'whatsapp',
	                'recipient_type' => 'individual',
	                'to' => $recipient,
	                'type' => 'text',
	                'text' => [
	                    'preview_url' => false,
	                    'body' => $message->body,
	                ],
	            ]);
	
	        if (!$response->successful()) {
	            return $this->markFailed(
	                $message,
	                $response->json('error.message')
	                    ?: 'Meta rejected the WhatsApp message.',
	                [
	                    'http_status' => $response->status(),
	                    'provider_error_code' => $response->json('error.code'),
	                ]
	            );
	        }
	
	        $providerMessageId = $response->json('messages.0.id');
	
	        if (!$providerMessageId) {
	            return $this->markFailed(
	                $message,
	                'Meta accepted the request but returned no message ID.',
	                [
	                    'http_status' => $response->status(),
	                ]
	            );
	        }
	
	        $message->update([
	            'provider_message_id' => $providerMessageId,
	            'status' => 'sent',
	            'sent_at' => now(),
	            'metadata' => [
	                'provider' => 'whatsapp',
	                'messaging_product' => 'whatsapp',
	            ],
	        ]);
	
	        return $message->fresh();
	
	    } catch (ConnectionException $exception) {
	        // The provider may have accepted the message before the
	        // connection failed. Never automatically resend it.
	        return $this->markFailed(
	            $message,
	            'Could not confirm the WhatsApp API response. '
	                . 'The delivery outcome may be uncertain.',
	            [
	                'delivery_outcome_uncertain' => true,
	            ]
	        );
	    }
	}


}
