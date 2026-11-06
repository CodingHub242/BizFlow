<?php

namespace Tests\Feature;

use App\Models\EngageContact;
use App\Models\EngageConversation;
use App\Models\EngageMessage;
use App\Models\Tenant;
use App\Services\Engage\EngageConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EngageConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected EngageConversationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(EngageConversationService::class);
    }

    private function createContact(Tenant $tenant): EngageContact
    {
        return EngageContact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Kwame Mensah',
            'phone' => '0241234567',
            'normalized_phone' => '+233241234567',
            'source' => 'whatsapp',
            'lifecycle_status' => 'prospect',
            'status' => 'active',
        ]);
    }

    public function test_inbound_message_creates_conversation_and_message(): void
    {
        $tenant = Tenant::factory()->create();
        $contact = $this->createContact($tenant);

        $message = $this->service->receiveInboundMessage(
            $tenant->id,
            $contact,
            [
                'provider_message_id' => 'wamid-001',
                'body' => 'Hello, I need more information.',
            ]
        );

        $this->assertDatabaseHas('engage_conversations', [
            'id' => $message->conversation_id,
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'unread_count' => 1,
        ]);

        $this->assertDatabaseHas('engage_messages', [
            'id' => $message->id,
            'tenant_id' => $tenant->id,
            'conversation_id' => $message->conversation_id,
            'provider_message_id' => 'wamid-001',
            'direction' => 'inbound',
            'body' => 'Hello, I need more information.',
            'status' => 'received',
        ]);
    }

    public function test_inbound_message_reuses_existing_open_conversation(): void
    {
        $tenant = Tenant::factory()->create();
        $contact = $this->createContact($tenant);

        $first = $this->service->receiveInboundMessage(
            $tenant->id,
            $contact,
            [
                'provider_message_id' => 'wamid-001',
                'body' => 'Hello.',
            ]
        );

        $second = $this->service->receiveInboundMessage(
            $tenant->id,
            $contact,
            [
                'provider_message_id' => 'wamid-002',
                'body' => 'I need help.',
            ]
        );

        $this->assertSame(
            $first->conversation_id,
            $second->conversation_id
        );

        $this->assertDatabaseCount('engage_conversations', 1);
        $this->assertDatabaseCount('engage_messages', 2);

        $this->assertDatabaseHas('engage_conversations', [
            'id' => $first->conversation_id,
            'unread_count' => 2,
        ]);
    }

    public function test_duplicate_provider_message_is_not_saved_twice(): void
    {
        $tenant = Tenant::factory()->create();
        $contact = $this->createContact($tenant);

        $payload = [
            'provider_message_id' => 'wamid-duplicate',
            'body' => 'Hello.',
        ];

        $first = $this->service->receiveInboundMessage(
            $tenant->id,
            $contact,
            $payload
        );

        $second = $this->service->receiveInboundMessage(
            $tenant->id,
            $contact,
            $payload
        );

        $this->assertSame($first->id, $second->id);

        $this->assertDatabaseCount('engage_messages', 1);
        $this->assertDatabaseCount('engage_conversations', 1);

        $this->assertDatabaseHas('engage_conversations', [
            'id' => $first->conversation_id,
            'unread_count' => 1,
        ]);
    }

    public function test_inbound_message_updates_conversation_timestamps(): void
    {
        $tenant = Tenant::factory()->create();
        $contact = $this->createContact($tenant);

        $receivedAt = now()->subMinutes(5)->startOfSecond();

        $message = $this->service->receiveInboundMessage(
            $tenant->id,
            $contact,
            [
                'provider_message_id' => 'wamid-003',
                'body' => 'Good morning.',
                'received_at' => $receivedAt,
            ]
        );

        $conversation = EngageConversation::findOrFail(
            $message->conversation_id
        );

        $this->assertEquals(
            $receivedAt->timestamp,
            $conversation->last_message_at->timestamp
        );

        $this->assertEquals(
            $receivedAt->timestamp,
            $conversation->last_inbound_at->timestamp
        );
    }

    public function test_contact_cannot_be_used_across_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $contact = $this->createContact($tenantA);

        $this->expectException(InvalidArgumentException::class);

        $this->service->receiveInboundMessage(
            $tenantB->id,
            $contact,
            [
                'body' => 'Hello.',
            ]
        );
    }

    public function test_invalid_empty_message_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $contact = $this->createContact($tenant);

        $this->expectException(InvalidArgumentException::class);

        $this->service->receiveInboundMessage(
            $tenant->id,
            $contact,
            []
        );
    }

    public function test_media_message_can_be_received_without_text_body(): void
    {
        $tenant = Tenant::factory()->create();
        $contact = $this->createContact($tenant);

        $message = $this->service->receiveInboundMessage(
            $tenant->id,
            $contact,
            [
                'provider_message_id' => 'wamid-media-001',
                'message_type' => 'image',
                'media' => [
                    'url' => 'https://example.com/image.jpg',
                    'mime_type' => 'image/jpeg',
                ],
            ]
        );

        $this->assertSame('image', $message->message_type);
        $this->assertNull($message->body);
        $this->assertNotNull($message->media);
    }

    public function test_message_without_provider_id_can_be_received(): void
    {
        $tenant = Tenant::factory()->create();
        $contact = $this->createContact($tenant);

        $message = $this->service->receiveInboundMessage(
            $tenant->id,
            $contact,
            [
                'body' => 'Hello without provider ID.',
            ]
        );

        $this->assertDatabaseHas('engage_messages', [
            'id' => $message->id,
            'provider_message_id' => null,
            'direction' => 'inbound',
        ]);
    }
}
