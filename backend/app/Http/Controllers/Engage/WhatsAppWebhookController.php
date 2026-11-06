<?php

namespace App\Http\Controllers\Engage;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Models\EngageWhatsAppNumber;
use App\Services\Engage\EngageContactService;
use App\Services\Engage\EngageConversationService;
use Illuminate\Http\JsonResponse;
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