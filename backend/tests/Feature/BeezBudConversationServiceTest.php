<?php

namespace Tests\Feature;

use App\Models\EngageContact;
use App\Models\EngageConversation;
use App\Models\EngageMessage;
use App\Models\Tenant;
use App\Models\TenantFeatureSetting;
use App\Services\Engage\BeezBudAiService;
use App\Services\Engage\BeezBudConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class BeezBudConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private EngageContact $contact;

    private EngageConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->contact = EngageContact::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->conversation = EngageConversation::factory()->create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'handling_mode' => EngageConversation::HANDLING_AI,
            'handling_version' => 0,
        ]);
    }

    private function createInboundMessage(
        array $attributes = []
    ): EngageMessage {
        return $this->conversation->messages()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'body' => 'Hello, I need some assistance.',
            'status' => 'received',
            'sender_type' => null,
            'handling_version' => 0,
            'media' => null,
            'metadata' => null,
        ], $attributes));
    }

    private function mockAiResponse(
        string $response = 'Hello! How can I help you today?'
    ): void {
        $ai = Mockery::mock(BeezBudAiService::class);

        $ai->shouldReceive('generateResponse')
            ->once()
            ->andReturn($response);

        $this->app->instance(BeezBudAiService::class, $ai);
    }

    public function test_it_prepares_an_ai_response_for_a_valid_inbound_text_message(): void
    {
        $inbound = $this->createInboundMessage();

        $this->mockAiResponse();

        $service = app(BeezBudConversationService::class);

        $response = $service->handleInboundTextMessage(
            $this->tenant->id,
            $this->conversation->id,
            $inbound->id
        );

        $this->assertInstanceOf(EngageMessage::class, $response);

        $this->assertSame('outbound', $response->direction);
        $this->assertSame('text', $response->message_type);
        $this->assertSame('ai', $response->sender_type);
        $this->assertSame('queued', $response->status);
        $this->assertSame(0, $response->handling_version);
        $this->assertSame(
            'Hello! How can I help you today?',
            $response->body
        );
        $this->assertNull($response->dispatched_at);
        $this->assertNull($response->provider_message_id);
    }

    public function test_it_does_not_process_messages_when_beezbud_is_disabled(): void
    {
        $this->tenant->featureSettings()
            ->updateOrCreate(
                [
                    'feature_key' => TenantFeatureSetting::FEATURE_BEEZBUD,
                ],
                [
                    'is_enabled' => false,
                ]
            );

        $inbound = $this->createInboundMessage();

        $ai = Mockery::mock(BeezBudAiService::class);

        $ai->shouldNotReceive('generateResponse');

        $this->app->instance(BeezBudAiService::class, $ai);

        $response = app(BeezBudConversationService::class)
            ->handleInboundTextMessage(
                $this->tenant->id,
                $this->conversation->id,
                $inbound->id
            );

        $this->assertNull($response);
    }

    public function test_it_does_not_process_a_human_owned_conversation(): void
    {
        $this->conversation->update([
            'handling_mode' => EngageConversation::HANDLING_HUMAN,
        ]);

        $inbound = $this->createInboundMessage();

        $ai = Mockery::mock(BeezBudAiService::class);

        $ai->shouldNotReceive('generateResponse');

        $this->app->instance(BeezBudAiService::class, $ai);

        $response = app(BeezBudConversationService::class)
            ->handleInboundTextMessage(
                $this->tenant->id,
                $this->conversation->id,
                $inbound->id
            );

        $this->assertNull($response);
    }

    public function test_it_ignores_stale_inbound_messages(): void
    {
        $this->conversation->update([
            'handling_version' => 2,
        ]);

        $inbound = $this->createInboundMessage([
            'handling_version' => 1,
        ]);

        $ai = Mockery::mock(BeezBudAiService::class);

        $ai->shouldNotReceive('generateResponse');

        $this->app->instance(BeezBudAiService::class, $ai);

        $response = app(BeezBudConversationService::class)
            ->handleInboundTextMessage(
                $this->tenant->id,
                $this->conversation->id,
                $inbound->id
            );

        $this->assertNull($response);
    }

    public function test_it_ignores_non_text_inbound_messages(): void
    {
        $inbound = $this->createInboundMessage([
            'message_type' => 'image',
            'body' => null,
            'media' => [
                'id' => 'media-123',
                'mime_type' => 'image/jpeg',
            ],
        ]);

        $ai = Mockery::mock(BeezBudAiService::class);

        $ai->shouldNotReceive('generateResponse');

        $this->app->instance(BeezBudAiService::class, $ai);

        $response = app(BeezBudConversationService::class)
            ->handleInboundTextMessage(
                $this->tenant->id,
                $this->conversation->id,
                $inbound->id
            );

        $this->assertNull($response);
    }

    public function test_it_rejects_a_message_from_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();

        $otherContact = EngageContact::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $otherConversation = EngageConversation::factory()->create([
            'tenant_id' => $otherTenant->id,
            'contact_id' => $otherContact->id,
            'handling_mode' => EngageConversation::HANDLING_AI,
            'handling_version' => 0,
        ]);

        $otherMessage = $otherConversation->messages()->create([
            'tenant_id' => $otherTenant->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'body' => 'This belongs to another tenant.',
            'status' => 'received',
            'handling_version' => 0,
        ]);

        $ai = Mockery::mock(BeezBudAiService::class);

        $ai->shouldNotReceive('generateResponse');

        $this->app->instance(BeezBudAiService::class, $ai);

        $this->expectException(
            \Illuminate\Database\Eloquent\ModelNotFoundException::class
        );

        app(BeezBudConversationService::class)
            ->handleInboundTextMessage(
                $this->tenant->id,
                $this->conversation->id,
                $otherMessage->id
            );
    }

    public function test_it_rejects_outbound_messages_as_ai_triggers(): void
    {
        $outbound = $this->createInboundMessage([
            'direction' => 'outbound',
            'sender_type' => 'human',
        ]);

        $ai = Mockery::mock(BeezBudAiService::class);

        $ai->shouldNotReceive('generateResponse');

        $this->app->instance(BeezBudAiService::class, $ai);

        $response = app(BeezBudConversationService::class)
            ->handleInboundTextMessage(
                $this->tenant->id,
                $this->conversation->id,
                $outbound->id
            );

        $this->assertNull($response);
    }


public function test_it_sends_only_the_correct_chronological_text_history_to_ai(): void
{
    $first = $this->createInboundMessage([
        'body' => 'Good morning.',
    ]);

    $outbound = $this->conversation->messages()->create([
        'tenant_id' => $this->tenant->id,
        'direction' => 'outbound',
        'message_type' => 'text',
        'body' => 'Good morning! How can I help?',
        'status' => 'sent',
        'sender_type' => 'human',
        'handling_version' => null,
    ]);

    $image = $this->createInboundMessage([
        'message_type' => 'image',
        'body' => null,
        'media' => ['id' => 'image-123'],
    ]);

    $trigger = $this->createInboundMessage([
        'body' => 'I want to know your prices.',
    ]);

    // This message arrived after the triggering message.
    $later = $this->createInboundMessage([
        'body' => 'Another question.',
    ]);

    $ai = Mockery::mock(BeezBudAiService::class);

    $ai->shouldReceive('generateResponse')
        ->once()
        ->withArgs(function ($instructions, $messages) use (
            $first,
            $outbound,
            $trigger,
            $later,
            $image
        ) {
            $this->assertIsString($instructions);

            $this->assertSame([
                [
                    'role' => 'user',
                    'content' => 'Good morning.',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Good morning! How can I help?',
                ],
                [
                    'role' => 'user',
                    'content' => 'I want to know your prices.',
                ],
            ], $messages);

            return true;
        })
        ->andReturn('I would be happy to help with our prices.');

    $this->app->instance(BeezBudAiService::class, $ai);

    $response = app(BeezBudConversationService::class)
        ->handleInboundTextMessage(
            $this->tenant->id,
            $this->conversation->id,
            $trigger->id
        );

    $this->assertSame('queued', $response->status);
    $this->assertSame('ai', $response->sender_type);
}

public function test_it_does_not_prepare_ai_response_if_human_takes_over_during_generation(): void
{
    $inbound = $this->createInboundMessage();

    $ai = Mockery::mock(BeezBudAiService::class);

    $ai->shouldReceive('generateResponse')
        ->once()
        ->andReturnUsing(function () {
            // Simulate a human taking over while OpenAI is generating.
            $this->conversation->update([
                'handling_mode' => EngageConversation::HANDLING_HUMAN,
                'handling_version' => 1,
            ]);

            return 'This response must never be queued.';
        });

    $this->app->instance(BeezBudAiService::class, $ai);

    try {
        app(BeezBudConversationService::class)
            ->handleInboundTextMessage(
                $this->tenant->id,
                $this->conversation->id,
                $inbound->id
            );

        $this->fail('Expected the stale AI response to be rejected.');
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'conversation',
            $exception->errors()
        );
    }

    $this->assertDatabaseMissing('engage_messages', [
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'sender_type' => 'ai',
        'body' => 'This response must never be queued.',
    ]);
}

public function test_it_returns_existing_ai_response_when_inbound_message_is_processed_again(): void
{
    $inbound = $this->createInboundMessage();

    // OpenAI must only be called once, even though we process twice.
    $this->mockAiResponse();

    $service = app(BeezBudConversationService::class);

    $firstResponse = $service->handleInboundTextMessage(
        $this->tenant->id,
        $this->conversation->id,
        $inbound->id
    );

    $secondResponse = $service->handleInboundTextMessage(
        $this->tenant->id,
        $this->conversation->id,
        $inbound->id
    );

    $this->assertInstanceOf(EngageMessage::class, $firstResponse);
    $this->assertInstanceOf(EngageMessage::class, $secondResponse);

    // Both calls must return the same response.
    $this->assertSame($firstResponse->id, $secondResponse->id);

    // Only one AI response should exist.
    $this->assertSame(
        1,
        EngageMessage::query()
            ->where('conversation_id', $this->conversation->id)
            ->where('direction', 'outbound')
            ->where('sender_type', 'ai')
            ->count()
    );

    // The inbound message must be marked as completed and linked.
    $this->assertDatabaseHas('engage_messages', [
        'id' => $inbound->id,
        'ai_processing_status' => 'completed',
        'ai_response_message_id' => $firstResponse->id,
        'ai_processing_token' => null,
    ]);
}

public function test_it_does_not_process_an_inbound_message_with_an_active_processing_lease(): void
{
    $inbound = $this->createInboundMessage();

    $processingToken = (string) \Illuminate\Support\Str::uuid();

    $inbound->update([
        'ai_processing_status' => 'processing',
        'ai_processing_started_at' => now()->subMinutes(2),
        'ai_processing_token' => $processingToken,
    ]);

    $ai = Mockery::mock(BeezBudAiService::class);

    $ai->shouldNotReceive('generateResponse');

    $this->app->instance(BeezBudAiService::class, $ai);

    $response = app(BeezBudConversationService::class)
        ->handleInboundTextMessage(
            $this->tenant->id,
            $this->conversation->id,
            $inbound->id
        );

    $this->assertNull($response);

    $this->assertDatabaseHas('engage_messages', [
        'id' => $inbound->id,
        'ai_processing_status' => 'processing',
        'ai_processing_token' => $processingToken,
    ]);
}
public function test_it_reclaims_an_inbound_message_when_its_processing_lease_expires(): void
{
    $inbound = $this->createInboundMessage();

    $oldToken = (string) \Illuminate\Support\Str::uuid();

    $inbound->update([
        'ai_processing_status' => 'processing',
        'ai_processing_started_at' => now()->subMinutes(11),
        'ai_processing_token' => $oldToken,
    ]);

    $this->mockAiResponse('Your previous request is being handled now.');

    $response = app(BeezBudConversationService::class)
        ->handleInboundTextMessage(
            $this->tenant->id,
            $this->conversation->id,
            $inbound->id
        );

    $this->assertInstanceOf(EngageMessage::class, $response);

    $this->assertSame(
        'Your previous request is being handled now.',
        $response->body
    );

    $this->assertDatabaseHas('engage_messages', [
        'id' => $inbound->id,
        'ai_processing_status' => 'completed',
        'ai_response_message_id' => $response->id,
        'ai_processing_token' => null,
    ]);

    $this->assertDatabaseMissing('engage_messages', [
        'id' => $inbound->id,
        'ai_processing_token' => $oldToken,
    ]);
}
public function test_it_retries_an_inbound_message_after_previous_processing_failed(): void
{
    $inbound = $this->createInboundMessage();

    $ai = Mockery::mock(BeezBudAiService::class);

    $ai->shouldReceive('generateResponse')
        ->twice()
        ->andReturnUsing(function () {
            static $attempt = 0;

            $attempt++;

            if ($attempt === 1) {
                throw new \RuntimeException('Temporary AI provider failure.');
            }

            return 'Hello again! How can I help?';
        });

    $this->app->instance(BeezBudAiService::class, $ai);

    $service = app(BeezBudConversationService::class);

    try {
        $service->handleInboundTextMessage(
            $this->tenant->id,
            $this->conversation->id,
            $inbound->id
        );

        $this->fail('Expected the first AI processing attempt to fail.');
    } catch (\RuntimeException $exception) {
        $this->assertSame(
            'Temporary AI provider failure.',
            $exception->getMessage()
        );
    }

    $this->assertDatabaseHas('engage_messages', [
        'id' => $inbound->id,
        'ai_processing_status' => 'failed',
        'ai_processing_token' => null,
    ]);

    $response = $service->handleInboundTextMessage(
        $this->tenant->id,
        $this->conversation->id,
        $inbound->id
    );

    $this->assertInstanceOf(EngageMessage::class, $response);
    $this->assertSame('Hello again! How can I help?', $response->body);

    $this->assertDatabaseHas('engage_messages', [
        'id' => $inbound->id,
        'ai_processing_status' => 'completed',
        'ai_response_message_id' => $response->id,
        'ai_processing_token' => null,
    ]);
}

public function test_it_does_not_create_a_response_when_another_worker_reclaims_the_processing_claim(): void
{
    $inbound = $this->createInboundMessage();

    $newToken = (string) \Illuminate\Support\Str::uuid();

    $ai = Mockery::mock(BeezBudAiService::class);

    $ai->shouldReceive('generateResponse')
        ->once()
        ->andReturnUsing(function () use ($inbound, $newToken) {
            // Simulate another worker reclaiming the message
            // while this worker is waiting for the AI response.
            $inbound->update([
                'ai_processing_status' => 'processing',
                'ai_processing_started_at' => now(),
                'ai_processing_token' => $newToken,
            ]);

            return 'This response belongs to the old worker.';
        });

    $this->app->instance(BeezBudAiService::class, $ai);

    $response = app(BeezBudConversationService::class)
        ->handleInboundTextMessage(
            $this->tenant->id,
            $this->conversation->id,
            $inbound->id
        );

    $this->assertNull($response);

    // The old worker must not create an outbound response.
    $this->assertDatabaseMissing('engage_messages', [
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'sender_type' => 'ai',
        'body' => 'This response belongs to the old worker.',
    ]);

    // The new worker's claim must remain untouched.
    $this->assertDatabaseHas('engage_messages', [
        'id' => $inbound->id,
        'ai_processing_status' => 'processing',
        'ai_processing_token' => $newToken,
    ]);
}

}
