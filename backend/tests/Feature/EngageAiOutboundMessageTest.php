<?php

namespace Tests\Feature;

use App\Models\EngageContact;
use App\Models\EngageConversation;
use App\Models\EngageMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Engage\EngageMessageService;
use Database\Seeders\BizFlowRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Client\ConnectionException;
use Spatie\Permission\PermissionRegistrar;
use App\Models\EngageWhatsAppAccount;
use App\Models\EngageWhatsAppNumber;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EngageAiOutboundMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;
    private EngageConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        (new BizFlowRolePermissionSeeder())->run();

        $this->tenant = Tenant::factory()->create();

        (new BizFlowRolePermissionSeeder())
            ->provisionTenantRoles($this->tenant->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        setPermissionsTeamId($this->tenant->id);

        $role = Role::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'Owner')
            ->firstOrFail();

        $this->user->assignRole($role);

        // Http::fake([
        //     'graph.facebook.com/*' => Http::response([
        //         'messaging_product' => 'whatsapp',
        //         'messages' => [
        //             ['id' => 'wamid.test-ai-message'],
        //         ],
        //     ], 200),
        // ]);

        config([
            'services.whatsapp.graph_api_version' => 'v23.0',
        ]);

        $account = EngageWhatsAppAccount::create([
            'tenant_id' => $this->tenant->id,
            'business_id' => 'test-business',
            'waba_id' => 'test-waba-' . $this->tenant->id,
            'name' => 'Test WhatsApp Account',
            'access_token' => 'test-access-token',
            'status' => 'active',
            'connected_by' => $this->user->id,
        ]);

        $number = EngageWhatsAppNumber::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_account_id' => $account->id,
            'phone_number_id' => 'test-phone-' . $this->tenant->id,
            'display_phone_number' => '+233201234567',
            'is_default' => true,
            'status' => 'active',
        ]);

        $contact = EngageContact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kwame Mensah',
            'phone' => '0241234567',
            'normalized_phone' => '+233241234567',
            'source' => 'whatsapp',
            'lifecycle_status' => 'prospect',
            'status' => 'active',
        ]);

        $this->conversation = EngageConversation::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'handling_mode' => EngageConversation::HANDLING_AI,
            'handling_version' => 0,
            'unread_count' => 0,
            'last_inbound_at' => now(),
            'whatsapp_number_id' => $number->id,
        ]);
    }

    public function test_beezbud_can_prepare_message_when_enabled_and_conversation_is_ai_owned(): void
    {
        $message = app(EngageMessageService::class)
            ->prepareOutboundAiMessage(
                $this->tenant->id,
                $this->conversation->id,
                'Welcome! How can we help you today?',
                0
            );

        $this->assertInstanceOf(EngageMessage::class, $message);

        $this->assertSame('outbound', $message->direction);
        $this->assertSame('text', $message->message_type);
        $this->assertSame(
            'Welcome! How can we help you today?',
            $message->body
        );
        $this->assertSame('queued', $message->status);
        $this->assertSame('ai', $message->sender_type);
        $this->assertSame(0, $message->handling_version);
        $this->assertNull($message->dispatched_at);

        $this->assertDatabaseHas('engage_messages', [
            'id' => $message->id,
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'outbound',
            'sender_type' => 'ai',
            'handling_version' => 0,
            'status' => 'queued',
        ]);
    }

    public function test_beezbud_cannot_prepare_message_when_conversation_is_human_owned(): void
    {
        $this->conversation->update([
            'handling_mode' => EngageConversation::HANDLING_HUMAN,
            'handling_version' => 1,
        ]);

        try {
            app(EngageMessageService::class)
                ->prepareOutboundAiMessage(
                    $this->tenant->id,
                    $this->conversation->id,
                    'This message must not be sent.',
                    1
                );

            $this->fail('Expected AI preparation to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'conversation',
                $exception->errors()
            );
        }

        $this->assertDatabaseMissing('engage_messages', [
            'conversation_id' => $this->conversation->id,
            'body' => 'This message must not be sent.',
        ]);
    }

    public function test_beezbud_cannot_prepare_message_when_tenant_has_disabled_it(): void
    {
        $this->tenant->featureSettings()->updateOrCreate(
            ['feature_key' => 'beezbud'],
            ['is_enabled' => false]
        );

        try {
            app(EngageMessageService::class)
                ->prepareOutboundAiMessage(
                    $this->tenant->id,
                    $this->conversation->id,
                    'This message must not be sent.',
                    0
                );

            $this->fail('Expected AI preparation to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'beezbud',
                $exception->errors()
            );
        }

        $this->assertDatabaseMissing('engage_messages', [
            'conversation_id' => $this->conversation->id,
            'body' => 'This message must not be sent.',
        ]);
    }


public function test_beezbud_can_dispatch_a_valid_prepared_message(): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [
                ['id' => 'wamid.test-ai-message'],
            ],
        ], 200),
    ]);

    $service = app(EngageMessageService::class);

    $message = $service->prepareOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        'Welcome! How can we help you?',
        0
    );

    $result = $service->dispatchOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id
    );

    $this->assertSame('sent', $result->status);
    $this->assertSame('ai', $result->sender_type);
    $this->assertNotNull($result->dispatched_at);
    $this->assertSame(
        'wamid.test-ai-message',
        $result->provider_message_id
    );

    Http::assertSentCount(1);
}

public function test_human_takeover_cancels_queued_ai_message(): void
{
    $service = app(EngageMessageService::class);

    $message = $service->prepareOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        'This response should be cancelled.',
        0
    );

    // Simulate an employee taking over after AI preparation.
    $this->conversation->update([
        'handling_mode' => EngageConversation::HANDLING_HUMAN,
        'handling_version' => 1,
    ]);

    $result = $service->dispatchOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id
    );

    $this->assertSame('cancelled', $result->status);
    $this->assertNull($result->dispatched_at);

    Http::assertNothingSent();
}

public function test_disabling_beezbud_cancels_queued_ai_message(): void
{
    $service = app(EngageMessageService::class);

    $message = $service->prepareOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        'This response should not be sent.',
        0
    );

    $this->tenant->featureSettings()->updateOrCreate(
        ['feature_key' => 'beezbud'],
        ['is_enabled' => false]
    );

    $result = $service->dispatchOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id
    );

    $this->assertSame('cancelled', $result->status);
    $this->assertNull($result->dispatched_at);

    Http::assertNothingSent();
}

public function test_already_dispatched_ai_message_is_not_sent_again(): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [
                ['id' => 'wamid.test-ai-message'],
            ],
        ], 200),
    ]);
    
    $service = app(EngageMessageService::class);

    $message = $service->prepareOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        'This message must only be sent once.',
        0
    );

    $firstResult = $service->dispatchOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id
    );

    $secondResult = $service->dispatchOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id
    );

    $this->assertSame('sent', $firstResult->status);
    $this->assertSame('sent', $secondResult->status);

    Http::assertSentCount(1);
}

public function test_meta_rejection_marks_ai_message_as_failed(): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => 'Invalid recipient',
                'code' => 100,
            ],
        ], 400),
    ]);

    $service = app(EngageMessageService::class);

    $message = $service->prepareOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        'Test rejected message.',
        0
    );

    $result = $service->dispatchOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id
    );

    $this->assertSame('failed', $result->status);
    $this->assertNotNull($result->dispatched_at);
    $this->assertSame(
        'Invalid recipient',
        $result->metadata['error']
    );

    Http::assertSentCount(1);
}
public function test_uncertain_ai_delivery_is_not_automatically_retried(): void
{
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('Connection timed out.');
    });

    $service = app(EngageMessageService::class);

    $message = $service->prepareOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        'Test uncertain delivery.',
        0
    );

    $firstResult = $service->dispatchOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id
    );

    $secondResult = $service->dispatchOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id
    );

    $this->assertSame('failed', $firstResult->status);
    $this->assertSame('failed', $secondResult->status);

    $this->assertNotNull($firstResult->dispatched_at);

    $this->assertTrue(
        $firstResult->metadata['delivery_outcome_uncertain']
    );

    $this->assertSame(1, $attempts);
}
public function test_stale_handling_version_cancels_queued_ai_message(): void
{
    $service = app(EngageMessageService::class);

    $message = $service->prepareOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        'This response has become stale.',
        0
    );

    // Simulate a newer handling state without changing AI ownership.
    $this->conversation->update([
        'handling_version' => 1,
    ]);

    $result = $service->dispatchOutboundAiMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id
    );

    $this->assertSame('cancelled', $result->status);
    $this->assertNull($result->dispatched_at);

    $this->assertSame(
        'stale_handling_version',
        $result->metadata['cancellation_reason']
    );

    Http::assertNothingSent();
}

}
