<?php

namespace Tests\Feature;

use App\Models\EngageContact;
use App\Models\EngageConversation;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\BizFlowRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EngageOutboundMessageTest extends TestCase
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

        $contact = EngageContact::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->conversation = EngageConversation::factory()->create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'unread_count' => 3,
        ]);
    }

    public function test_owner_can_queue_outbound_message(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/engage/conversations/{$this->conversation->id}/messages",
                [
                    'body' => 'Hello, how can we help you?',
                ]
            );

        $response->assertCreated()
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.message_type', 'text')
            ->assertJsonPath('data.body', 'Hello, how can we help you?')
            ->assertJsonPath('data.status', 'queued');

        $this->assertDatabaseHas('engage_messages', [
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'outbound',
            'body' => 'Hello, how can we help you?',
            'status' => 'queued',
        ]);
    }

    public function test_outbound_message_updates_conversation_timestamp(): void
    {
        $this->travelTo(now()->addMinute());

        $this->actingAs($this->user)
            ->postJson(
                "/api/engage/conversations/{$this->conversation->id}/messages",
                ['body' => 'Thank you for contacting us.']
            )
            ->assertCreated();

        $this->assertDatabaseHas('engage_conversations', [
            'id' => $this->conversation->id,
            'unread_count' => 3,
        ]);

       $this->assertEquals(
    now()->timestamp,
    $this->conversation->fresh()->last_message_at->timestamp
);

        $this->travelBack();
    }

    public function test_empty_message_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson(
                "/api/engage/conversations/{$this->conversation->id}/messages",
                ['body' => '   ']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertDatabaseMissing('engage_messages', [
            'conversation_id' => $this->conversation->id,
        ]);
    }

    public function test_message_body_is_required(): void
    {
        $this->actingAs($this->user)
            ->postJson(
                "/api/engage/conversations/{$this->conversation->id}/messages",
                []
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_message_cannot_be_sent_to_another_tenants_conversation(): void
    {
        $otherTenant = Tenant::factory()->create();

        $otherContact = EngageContact::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $otherConversation = EngageConversation::factory()->create([
            'tenant_id' => $otherTenant->id,
            'contact_id' => $otherContact->id,
        ]);

        $this->actingAs($this->user)
            ->postJson(
                "/api/engage/conversations/{$otherConversation->id}/messages",
                ['body' => 'This should not be saved.']
            )
            ->assertNotFound();

        $this->assertDatabaseMissing('engage_messages', [
            'conversation_id' => $otherConversation->id,
            'body' => 'This should not be saved.',
        ]);
    }

    public function test_unauthenticated_user_cannot_queue_message(): void
    {
        $this->postJson(
            "/api/engage/conversations/{$this->conversation->id}/messages",
            ['body' => 'Hello']
        )->assertUnauthorized();
    }

    public function test_user_without_reply_permission_cannot_queue_message(): void
{
    setPermissionsTeamId($this->tenant->id);

    $role = Role::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Engage Viewer',
        'guard_name' => 'web',
    ]);

    $role->givePermissionTo('engage.conversations.view');

    $viewer = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $viewer->assignRole($role);

    $response = $this->actingAs($viewer, 'sanctum')
        ->postJson(
            "/api/engage/conversations/{$this->conversation->id}/messages",
            ['body' => 'Hello, customer!']
        );

    $response->assertForbidden();

    $this->assertDatabaseMissing('engage_messages', [
        'conversation_id' => $this->conversation->id,
        'body' => 'Hello, customer!',
    ]);
}
}
