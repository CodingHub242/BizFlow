<?php

namespace App\Services\Engage;

use App\Models\EngageConversation;
use App\Models\EngageMessage;
use App\Models\Tenant;
use Illuminate\Support\Collection;

class BeezBudConversationService
{
    public function __construct(
        private BeezBudPromptService $promptService,
        private BeezBudAiService $aiService,
        private EngageMessageService $messageService,
    ) {
    }

    /**
     * Process one inbound text message and prepare BeezBud's response.
     *
     * Returns null when the message is not eligible for AI processing.
     * This method does not dispatch anything to WhatsApp.
     */
    public function handleInboundTextMessage(
        int $tenantId,
        int $conversationId,
        int $inboundMessageId
    ): ?EngageMessage {
        // Confirm that BeezBud is enabled for this tenant.
        $tenant = Tenant::query()->findOrFail($tenantId);

        if (!$tenant->isBeezBudEnabled()) {
            return null;
        }

        // Always scope the conversation to its tenant.
        $conversation = EngageConversation::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($conversationId);

        if (
            $conversation->handling_mode !==
            EngageConversation::HANDLING_AI
        ) {
            return null;
        }

        // Retrieve the triggering message within the same tenant
        // and conversation.
        $inboundMessage = EngageMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->findOrFail($inboundMessageId);

        // Only genuine inbound text messages can trigger AI generation.
        if (
            $inboundMessage->direction !== 'inbound' ||
            $inboundMessage->message_type !== 'text' ||
            !is_string($inboundMessage->body) ||
            trim($inboundMessage->body) === ''
        ) {
            return null;
        }

        // The inbound message must belong to the current
        // conversation ownership version.
        if (
            $inboundMessage->handling_version === null ||
            (int) $inboundMessage->handling_version !==
            (int) $conversation->handling_version
        ) {
            return null;
        }

        // Build a chronological text-only conversation history.
        $messages = $this->buildConversationHistory(
            $tenantId,
            $conversationId,
            $inboundMessageId
        );

        if ($messages->isEmpty()) {
            return null;
        }

        $prompt = $this->promptService->buildInstructions(
            $tenant->name
        );

        // Generate the response.
        $response = $this->aiService->generateResponse(
            $prompt,
            $messages->all()
        );

        // This method rechecks tenant feature status, ownership,
        // and handling version inside a database transaction.
        // If a human took over during generation, preparation fails.
        return $this->messageService->prepareOutboundAiMessage(
            $tenantId,
            $conversationId,
            $response,
            (int) $inboundMessage->handling_version
        );
    }

    /**
     * Build the recent conversation history up to the triggering message.
     *
     * Media messages and unsuccessful outbound messages are excluded.
     *
     * @return Collection<int, array{role: string, content: string}>
     */
    private function buildConversationHistory(
        int $tenantId,
        int $conversationId,
        int $inboundMessageId
    ): Collection {
        $history = EngageMessage::query()
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
            ->values();

        return $history
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
