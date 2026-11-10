<?php

namespace App\Services\Engage;

use App\Models\EngageConversation;
use App\Models\EngageMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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
}
