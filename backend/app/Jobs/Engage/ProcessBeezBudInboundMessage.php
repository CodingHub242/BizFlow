<?php

namespace App\Jobs\Engage;

use App\Services\Engage\BeezBudConversationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBeezBudInboundMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(
        public int $tenantId,
        public int $conversationId,
        public int $inboundMessageId,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        BeezBudConversationService $beezBudService
    ): void {
        $beezBudService->handleInboundTextMessage(
            $this->tenantId,
            $this->conversationId,
            $this->inboundMessageId
        );
    }
}
