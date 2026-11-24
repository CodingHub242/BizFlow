<?php

namespace App\Services\Engage;

use App\Models\EngageConversation;
use App\Models\EngageMessage;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BeezBudConversationService
{
    private const PROCESSING_LEASE_MINUTES = 10;

    public function __construct(
        private BeezBudPromptService $promptService,
        private BeezBudAiService $aiService,
        private EngageMessageService $messageService,
    ) {
    }

    /**
     * Process one inbound text message and prepare BeezBud's response.
     *
     * Returns the prepared response, or null when processing is
     * skipped or another worker already owns the claim.
     *
     * This method never dispatches directly to WhatsApp.
     */
    public function handleInboundTextMessage(
        int $tenantId,
        int $conversationId,
        int $inboundMessageId
    ): ?EngageMessage {
        $claim = $this->claimInboundMessage(
            $tenantId,
            $conversationId,
            $inboundMessageId
        );

        if ($claim['state'] === 'completed') {
            return EngageMessage::query()
                ->where('tenant_id', $tenantId)
                ->find($claim['response_id']);
        }

        if ($claim['state'] !== 'claimed') {
            return null;
        }

        $token = $claim['token'];
        $handlingVersion = $claim['handling_version'];

        try {
            $tenant = Tenant::query()->findOrFail($tenantId);

            $messages = $this->buildConversationHistory(
                $tenantId,
                $conversationId,
                $inboundMessageId
            );

            if ($messages->isEmpty()) {
                throw new \RuntimeException(
                    'No usable conversation history was found.'
                );
            }

            $prompt = $this->promptService->buildInstructions(
                $tenant->name
            );

            $responseBody = $this->aiService->generateResponse(
                $prompt,
                $messages->all()
            );

            // This existing method performs its own final ownership,
            // tenant feature and handling-version checks.
            // $response = $this->messageService->prepareOutboundAiMessage(
            //     $tenantId,
            //     $conversationId,
            //     $responseBody,
            //     $handlingVersion
            // );
            return $this->messageService->prepareAndCompleteInboundAiMessage(
                $tenantId,
                $conversationId,
                $inboundMessageId,
                $responseBody,
                $token,
                $handlingVersion
            );

            // return $this->completeClaim(
            //     $tenantId,
            //     $conversationId,
            //     $inboundMessageId,
            //     $response,
            //     $token,
            //     $handlingVersion
            // );
        } catch (Throwable $exception) {
            $this->releaseFailedClaim(
                $tenantId,
                $conversationId,
                $inboundMessageId,
                $token
            );

            throw $exception;
        }
    }

    /**
     * Atomically claim an eligible inbound message.
     *
     * @return array{
     *     state: string,
     *     token?: string,
     *     handling_version?: int,
     *     response_id?: int
     * }
     */
    private function claimInboundMessage(
        int $tenantId,
        int $conversationId,
        int $inboundMessageId
    ): array {
        return DB::transaction(function () use (
            $tenantId,
            $conversationId,
            $inboundMessageId
        ) {
            $tenant = Tenant::query()->findOrFail($tenantId);

            if (!$tenant->isBeezBudEnabled()) {
                return ['state' => 'skipped'];
            }

            $conversation = EngageConversation::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($conversationId);

            if (
                $conversation->handling_mode !==
                EngageConversation::HANDLING_AI
            ) {
                return ['state' => 'skipped'];
            }

            $message = EngageMessage::query()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->lockForUpdate()
                ->findOrFail($inboundMessageId);

            if (
                $message->direction !== 'inbound' ||
                $message->message_type !== 'text' ||
                !is_string($message->body) ||
                trim($message->body) === '' ||
                $message->handling_version === null ||
                (int) $message->handling_version !==
                    (int) $conversation->handling_version
            ) {
                return ['state' => 'skipped'];
            }

            if ($message->ai_processing_status === 'completed') {
                return [
                    'state' => 'completed',
                    'response_id' => $message->ai_response_message_id,
                ];
            }

            if ($message->ai_processing_status === 'cancelled') {
                return ['state' => 'skipped'];
            }

            if ($message->ai_processing_status === 'processing') {
                $leaseExpired = !$message->ai_processing_started_at ||
                    $message->ai_processing_started_at->lte(
                        now()->subMinutes(self::PROCESSING_LEASE_MINUTES)
                    );

                if (!$leaseExpired) {
                    return ['state' => 'already_processing'];
                }
            }

            // Null or failed states can be claimed.
            // Expired processing claims can also be reclaimed.
            $token = (string) Str::uuid();

            $message->update([
                'ai_processing_status' => 'processing',
                'ai_processing_started_at' => now(),
                'ai_processing_token' => $token,
                'ai_response_message_id' => null,
            ]);

            return [
                'state' => 'claimed',
                'token' => $token,
                'handling_version' => (int) $message->handling_version,
            ];
        }, 3);
    }

    /**
     * Link the prepared response and complete the inbound claim.
     */
    // private function completeClaim(
    //     int $tenantId,
    //     int $conversationId,
    //     int $inboundMessageId,
    //     EngageMessage $response,
    //     string $token,
    //     int $handlingVersion
    // ): ?EngageMessage {
    //     return DB::transaction(function () use (
    //         $tenantId,
    //         $conversationId,
    //         $inboundMessageId,
    //         $response,
    //         $token,
    //         $handlingVersion
    //     ) {
    //         $tenant = Tenant::query()->findOrFail($tenantId);

    //         $conversation = EngageConversation::query()
    //             ->where('tenant_id', $tenantId)
    //             ->lockForUpdate()
    //             ->findOrFail($conversationId);

    //         $inbound = EngageMessage::query()
    //             ->where('tenant_id', $tenantId)
    //             ->where('conversation_id', $conversationId)
    //             ->lockForUpdate()
    //             ->findOrFail($inboundMessageId);

    //         $currentClaim = $inbound->ai_processing_token === $token;

    //         $stillEligible = $tenant->isBeezBudEnabled()
    //             && $conversation->handling_mode ===
    //                 EngageConversation::HANDLING_AI
    //             && (int) $conversation->handling_version ===
    //                 $handlingVersion;

    //         if (!$currentClaim || !$stillEligible) {
    //             $response->update([
    //                 'status' => 'cancelled',
    //                 'metadata' => [
    //                     'provider' => 'whatsapp',
    //                     'cancellation_reason' => !$stillEligible
    //                         ? 'conversation_ownership_changed'
    //                         : 'processing_claim_expired',
    //                 ],
    //             ]);

    //             if ($currentClaim) {
    //                 $inbound->update([
    //                     'ai_processing_status' => 'cancelled',
    //                     'ai_processing_token' => null,
    //                 ]);
    //             }

    //             return null;
    //         }

    //         $inbound->update([
    //             'ai_processing_status' => 'completed',
    //             'ai_response_message_id' => $response->id,
    //             'ai_processing_token' => null,
    //         ]);

    //         return $response->fresh();
    //     }, 3);
    // }

    /**
     * Release a failed or invalid claim.
     *
     * A worker can only update the claim if it still owns its token.
     */
    private function releaseFailedClaim(
        int $tenantId,
        int $conversationId,
        int $inboundMessageId,
        string $token
    ): void {
        DB::transaction(function () use (
            $tenantId,
            $conversationId,
            $inboundMessageId,
            $token
        ) {
            $conversation = EngageConversation::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($conversationId)
                ->lockForUpdate()
                ->first();

            $message = EngageMessage::query()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->lockForUpdate()
                ->find($inboundMessageId);

            if (
                !$conversation ||
                !$message ||
                $message->ai_processing_token !== $token
            ) {
                return;
            }

            $tenant = Tenant::query()->find($tenantId);

            $stillEligible = $tenant?->isBeezBudEnabled()
                && $conversation->handling_mode ===
                    EngageConversation::HANDLING_AI
                && (int) $conversation->handling_version ===
                    (int) $message->handling_version;

            $message->update([
                'ai_processing_status' => $stillEligible
                    ? 'failed'
                    : 'cancelled',
                'ai_processing_token' => null,
            ]);
        }, 3);
    }

    /**
     * Build chronological text-only conversation history.
     *
     * @return Collection<int, array{role: string, content: string}>
     */
    private function buildConversationHistory(
        int $tenantId,
        int $conversationId,
        int $inboundMessageId
    ): Collection {
        return EngageMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->where('id', '<=', $inboundMessageId)
            ->where('message_type', 'text')
            ->whereNotNull('body')
            ->where('body', '<>', '')
            ->where(function ($query) {
                $query->where('direction', 'inbound')
                    ->orWhere(function ($outbound) {
                        $outbound->where('direction', 'outbound')
                            ->whereIn('status', [
                                'sent',
                                'delivered',
                                'read',
                            ]);
                    });
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->values()
            ->map(function (EngageMessage $message) {
                return [
                    'role' => $message->direction === 'inbound'
                        ? 'user'
                        : 'assistant',
                    'content' => trim($message->body),
                ];
            })
            ->values();
    }
}
