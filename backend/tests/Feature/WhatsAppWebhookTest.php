<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\EngageWhatsAppAccount;
use App\Models\EngageWhatsAppNumber;
use App\Models\EngageContact;
use App\Models\EngageConversation;
use App\Models\EngageMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $appSecret = 'test-meta-app-secret';

    private Tenant $tenant;

    private EngageWhatsAppNumber $whatsappNumber;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => $this->appSecret,
            'services.whatsapp.webhook_verify_token' => 'test-verify-token',
        ]);

        $this->tenant = Tenant::factory()->create();

        $account = EngageWhatsAppAccount::create([
            'tenant_id' => $this->tenant->id,
            'waba_id' => 'test-waba-123',
            'name' => 'Test WhatsApp Business',
            'status' => 'active',
        ]);

        $this->whatsappNumber = EngageWhatsAppNumber::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_account_id' => $account->id,
            'phone_number_id' => '123456789012345',
            'display_phone_number' => '+15551392567',
            'verified_name' => 'Test Business',
            'status' => 'active',
            'is_default' => true,
        ]);
    }

    private function sendWebhook(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $signature = 'sha256=' . hash_hmac(
            'sha256',
            $body,
            $this->appSecret
        );

        return $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $body
        );
    }

    private function messagePayload(
        string $messageId = 'wamid.test-message-001'
    ): array {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'test-waba-123',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '+15551392567',
                                    'phone_number_id' => '123456789012345',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => [
                                            'name' => 'Test Customer',
                                        ],
                                        'wa_id' => '233241234567',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '233241234567',
                                        'id' => $messageId,
                                        'timestamp' => (string) now()->timestamp,
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'Hello Bizflow!',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_valid_meta_signature_is_accepted(): void
    {
        $response = $this->sendWebhook(
            $this->messagePayload()
        );

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('engage_messages', [
            'provider_message_id' => 'wamid.test-message-001',
            'direction' => 'inbound',
            'body' => 'Hello Bizflow!',
        ]);

        $this->assertDatabaseHas('engage_contacts', [
            'tenant_id' => $this->tenant->id,
            'normalized_phone' => '+233241234567',
        ]);

        $this->assertDatabaseHas('engage_conversations', [
            'tenant_id' => $this->tenant->id,
            'whatsapp_number_id' => $this->whatsappNumber->id,
            'unread_count' => 1,
        ]);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $body = json_encode(
            $this->messagePayload(),
            JSON_THROW_ON_ERROR
        );

        $response = $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
            ],
            $body
        );

        $response->assertUnauthorized();

        $this->assertDatabaseCount('engage_messages', 0);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $response = $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($this->messagePayload(), JSON_THROW_ON_ERROR)
        );

        $response->assertUnauthorized();

        $this->assertDatabaseCount('engage_messages', 0);
    }

    public function test_duplicate_webhook_does_not_duplicate_message(): void
    {
        $payload = $this->messagePayload();

        $this->sendWebhook($payload)->assertOk();
        $this->sendWebhook($payload)->assertOk();

        $this->assertDatabaseCount('engage_messages', 1);
        $this->assertDatabaseCount('engage_conversations', 1);

        $conversation = EngageConversation::firstOrFail();

        $this->assertEquals(1, $conversation->unread_count);
    }

    public function test_unknown_whatsapp_number_is_ignored(): void
    {
        $payload = $this->messagePayload();

        $payload['entry'][0]['changes'][0]['value']
            ['metadata']['phone_number_id'] = '999999999';

        $response = $this->sendWebhook($payload);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseCount('engage_messages', 0);
        $this->assertDatabaseCount('engage_contacts', 0);
    }

    public function test_status_only_webhook_is_accepted_without_creating_message(): void
    {
        $payload = $this->messagePayload();

        $payload['entry'][0]['changes'][0]['value']['messages'] = [];

        $payload['entry'][0]['changes'][0]['value']['statuses'] = [
            [
                'id' => 'wamid.outbound-test',
                'status' => 'delivered',
            ],
        ];

        $response = $this->sendWebhook($payload);

        $response->assertOk();

        $this->assertDatabaseCount('engage_messages', 0);
    }

    public function test_same_customer_can_have_separate_conversations_for_two_whatsapp_numbers(): void
{
    // Create a second number under the same WhatsApp Business Account.
    $secondNumber = EngageWhatsAppNumber::create([
        'tenant_id' => $this->tenant->id,
        'whatsapp_account_id' => $this->whatsappNumber->whatsapp_account_id,
        'phone_number_id' => '987654321098765',
        'display_phone_number' => '+15551392568',
        'verified_name' => 'Second Business Number',
        'status' => 'active',
        'is_default' => false,
    ]);

    // First incoming message goes to the first number.
    $firstPayload = $this->messagePayload('wamid.first-number');
    $this->sendWebhook($firstPayload)->assertOk();

    // Second incoming message comes from the same customer,
    // but is addressed to the second number.
    $secondPayload = $this->messagePayload('wamid.second-number');

    $secondPayload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id']
        = $secondNumber->phone_number_id;

    $secondPayload['entry'][0]['changes'][0]['value']['metadata']['display_phone_number']
        = $secondNumber->display_phone_number;

    $this->sendWebhook($secondPayload)->assertOk();

    $this->assertDatabaseCount('engage_contacts', 1);
    $this->assertDatabaseCount('engage_conversations', 2);
    $this->assertDatabaseCount('engage_messages', 2);

    $this->assertDatabaseHas('engage_conversations', [
        'whatsapp_number_id' => $this->whatsappNumber->id,
        'unread_count' => 1,
    ]);

    $this->assertDatabaseHas('engage_conversations', [
        'whatsapp_number_id' => $secondNumber->id,
        'unread_count' => 1,
    ]);
}

public function test_message_is_routed_to_the_tenant_owning_the_receiving_number(): void
{
    $secondTenant = Tenant::factory()->create();

    $secondAccount = EngageWhatsAppAccount::create([
        'tenant_id' => $secondTenant->id,
        'waba_id' => 'second-tenant-waba',
        'name' => 'Second Tenant Account',
        'status' => 'active',
    ]);

    $secondNumber = EngageWhatsAppNumber::create([
        'tenant_id' => $secondTenant->id,
        'whatsapp_account_id' => $secondAccount->id,
        'phone_number_id' => '555555555555555',
        'display_phone_number' => '+15551392569',
        'status' => 'active',
    ]);

    $payload = $this->messagePayload('wamid.second-tenant');

    $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id']
        = $secondNumber->phone_number_id;

    $this->sendWebhook($payload)->assertOk();

    $this->assertDatabaseHas('engage_contacts', [
        'tenant_id' => $secondTenant->id,
        'normalized_phone' => '+233241234567',
    ]);

    $this->assertDatabaseMissing('engage_contacts', [
        'tenant_id' => $this->tenant->id,
        'normalized_phone' => '+233241234567',
    ]);

    $this->assertDatabaseHas('engage_conversations', [
        'tenant_id' => $secondTenant->id,
        'whatsapp_number_id' => $secondNumber->id,
    ]);
}
}
