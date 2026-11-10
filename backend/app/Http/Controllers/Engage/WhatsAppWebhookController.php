<?php

namespace App\Http\Controllers\Engage;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Models\EngageWhatsAppNumber;
use App\Services\Engage\EngageContactService;
use App\Services\Engage\EngageConversationService;
use Illuminate\Http\JsonResponse;
use App\Models\EngageMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Verify the WhatsApp webhook subscription.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $verifyToken = config('services.whatsapp.webhook_verify_token');

        if (
            $mode === 'subscribe' &&
            filled($verifyToken) &&
            is_string($token) &&
            hash_equals($verifyToken, $token) &&
            is_string($challenge)
        ) {
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(
    Request $request,
    EngageContactService $contactService,
    EngageConversationService $conversationService
): JsonResponse {
    // 1. Verify Meta's request signature.
    $appSecret = config('services.whatsapp.app_secret');
    $signature = $request->header('X-Hub-Signature-256');

    if (
        !filled($appSecret) ||
        !is_string($signature) ||
        !str_starts_with($signature, 'sha256=')
    ) {
        return response()->json(['message' => 'Invalid signature.'], 401);
    }

    $expectedSignature = 'sha256=' . hash_hmac(
        'sha256',
        $request->getContent(),
        $appSecret
    );

    if (!hash_equals($expectedSignature, $signature)) {
        return response()->json(['message' => 'Invalid signature.'], 401);
    }

    // 2. Decode the original webhook payload.
    $payload = json_decode($request->getContent(), true);

    if (!is_array($payload)) {
        return response()->json(['message' => 'Invalid payload.'], 400);
    }

    // Ignore non-WhatsApp or status-only notifications.
    if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
        return response()->json(['message' => 'Event ignored.']);
    }

    // 3. Process each account notification and message.
    foreach ($payload['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            if (($change['field'] ?? null) !== 'messages') {
                continue;
            }

            $value = $change['value'] ?? [];
            $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

            if (!$phoneNumberId) {
                continue;
            }

            // 4. Resolve the receiving number and its tenant.
            $whatsappNumber = EngageWhatsAppNumber::query()
                ->with('account')
                ->where('phone_number_id', $phoneNumberId)
                ->where('status', 'active')
               ->whereHas('account', function ($query) {
                    $query->where('status', 'active')
                        ->whereColumn(
                            'engage_whatsapp_accounts.tenant_id',
                            'engage_whatsapp_numbers.tenant_id'
                        );
                })
                ->first();

            if (!$whatsappNumber) {
                Log::warning('WhatsApp webhook received for an unregistered number.', [
                    'phone_number_id' => $phoneNumberId,
                ]);

                continue;
            }

            $tenantId = (int) $whatsappNumber->tenant_id;

            // 5. Process outbound message status notifications.
            foreach ($value['statuses'] ?? [] as $statusUpdate) {
                $providerMessageId = $statusUpdate['id'] ?? null;
                $providerStatus = $statusUpdate['status'] ?? null;

                if (
                    !is_string($providerMessageId) ||
                    !is_string($providerStatus) ||
                    !in_array($providerStatus, [
                        'sent',
                        'delivered',
                        'read',
                        'failed',
                    ], true)
                ) {
                    continue;
                }

                // Find the outbound message belonging to this tenant
                // and this specific WhatsApp number.
                $message = EngageMessage::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider_message_id', $providerMessageId)
                    ->where('direction', 'outbound')
                    ->whereHas('conversation', function ($query) use ($tenantId, $whatsappNumber) {
                        $query
                            ->where('tenant_id', $tenantId)
                            ->where('whatsapp_number_id', $whatsappNumber->id);
                    })
                    ->first();

                if (!$message) {
                    Log::warning('WhatsApp status received for an unknown outbound message.', [
                        'tenant_id' => $tenantId,
                        'phone_number_id' => $phoneNumberId,
                        'provider_message_id' => $providerMessageId,
                        'provider_status' => $providerStatus,
                    ]);

                    continue;
                }

                $statusTimestamp = isset($statusUpdate['timestamp'])
                    ? Carbon::createFromTimestamp((int) $statusUpdate['timestamp'])
                    : now();

                // Prevent delayed webhook events from downgrading message status.
                $statusRank = [
                    'queued' => 0,
                    'sent' => 1,
                    'delivered' => 2,
                    'read' => 3,
                ];

                $currentRank = $statusRank[$message->status] ?? -1;
                $incomingRank = $statusRank[$providerStatus] ?? -1;

                if (
                    $message->status === 'failed' &&
                    $providerStatus !== 'failed'
                ) {
                    continue;
                }

                if (
                    $providerStatus !== 'failed' &&
                    $incomingRank <= $currentRank
                ) {
                    continue;
                }

                if (
                    $providerStatus === 'failed' &&
                    in_array($message->status, ['delivered', 'read'], true)
                ) {
                    continue;
                }

                $metadata = $message->metadata ?? [];
                $metadata['provider_status'] = $providerStatus;

                if ($providerStatus === 'failed') {
                    $metadata['provider_errors'] = $statusUpdate['errors'] ?? [];
                }

                $updates = [
                    'status' => $providerStatus,
                    'metadata' => $metadata,
                ];

                if ($providerStatus === 'sent') {
                    $updates['sent_at'] = $statusTimestamp;
                } elseif ($providerStatus === 'delivered') {
                    $updates['delivered_at'] = $statusTimestamp;
                } elseif ($providerStatus === 'read') {
                    $updates['read_at'] = $statusTimestamp;
                }

                $message->update($updates);
            }


            // 5. Process incoming messages.
            foreach ($value['messages'] ?? [] as $incoming) {
                $senderPhone = $incoming['from'] ?? null;

                if (!$senderPhone) {
                    continue;
                }

                $profileName = null;

                foreach ($value['contacts'] ?? [] as $contact) {
                    if (($contact['wa_id'] ?? null) === $senderPhone) {
                        $profileName = $contact['profile']['name'] ?? null;
                        break;
                    }
                }

                $contact = $contactService->resolveInboundContact(
                    $tenantId,
                    $senderPhone,
                    $profileName
                );

                $type = $incoming['type'] ?? 'text';

                $body = match ($type) {
                    'text' => $incoming['text']['body'] ?? null,
                    'image', 'video', 'document', 'audio', 'sticker'
                        => $incoming[$type]['caption'] ?? null,
                    'button' => $incoming['button']['text'] ?? null,
                    'interactive' => $incoming['interactive']['button_reply']['title']
                        ?? $incoming['interactive']['list_reply']['title']
                        ?? null,
                    default => null,
                };

                $media = null;

                if (in_array($type, [
                    'image', 'video', 'document', 'audio', 'sticker',
                ], true)) {
                    $media = $incoming[$type] ?? null;
                }

                // Unsupported types are retained as metadata for later handling.
                if ($body === null && $media === null) {
                    $media = [
                        'unsupported_type' => $type,
                        'payload' => $incoming[$type] ?? null,
                    ];
                }

                $timestamp = isset($incoming['timestamp'])
                    ? \Carbon\Carbon::createFromTimestamp((int) $incoming['timestamp'])
                    : now();

                $conversationService->receiveInboundMessage(
                    $tenantId,
                    $contact,
                    [
                        'channel' => 'whatsapp',
                        'whatsapp_number_id' => $whatsappNumber->id,
                        'provider_message_id' => $incoming['id'] ?? null,
                        'body' => $body,
                        'media' => $media,
                        'message_type' => $type,
                        'received_at' => $timestamp,
                        'metadata' => [
                            'phone_number_id' => $phoneNumberId,
                            'display_phone_number' =>
                                $value['metadata']['display_phone_number'] ?? null,
                        ],
                    ]
                );
            }
        }
    }

    return response()->json(['success' => true]);
}
}