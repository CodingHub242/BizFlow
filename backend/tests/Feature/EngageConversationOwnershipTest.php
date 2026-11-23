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

class EngageConversationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

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
    }

    private function createConversation(
        ?int $tenantId = null,
        string $mode = 'ai'
    ): EngageConversation {
        $tenantId ??= $this->tenant->id;

        $contact = EngageContact::create([
            'tenant_id' => $tenantId,
            'name' => 'Kwame Mensah',
            'phone' => '0241234567',
            'normalized_phone' => '+233241234567',
            'source' => 'whatsapp',
            'lifecycle_status' => 'prospect',
            'status' => 'active',
        ]);

        return EngageConversation::create([
            'tenant_id' => $tenantId,
            'contact_id' => $contact->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'handling_mode' => $mode,
            'handling_version' => 0,
            'unread_count' => 0,
        ]);
    }

    public function test_owner_can_take_over_ai_conversation(): void
    {
        $conversation = $this->createConversation();

        $this->actingAs($this->user)
            ->postJson("/api/engage/conversations/{$conversation->id}/takeover")
            ->assertOk()
            ->assertJsonPath('data.handling_mode', 'human')
            ->assertJsonPath('data.handling_version', 1)
            ->assertJsonPath('data.handling_changed_by', $this->user->id);

        $this->assertDatabaseHas('engage_conversations', [
            'id' => $conversation->id,
            'handling_mode' => 'human',
            'handling_version' => 1,
            'handling_changed_by' => $this->user->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'action' => 'conversation.human_takeover',
            'auditable_id' => $conversation->id,
        ]);
    }

    public function test_owner_can_hand_back_conversation_to_beezbud(): void
    {
        $conversation = $this->createConversation(
            mode: 'human'
        );

        $this->actingAs($this->user)
            ->postJson("/api/engage/conversations/{$conversation->id}/hand-back")
            ->assertOk()
            ->assertJsonPath('data.handling_mode', 'ai')
            ->assertJsonPath('data.handling_version', 1);

        $this->assertDatabaseHas('engage_conversations', [
            'id' => $conversation->id,
            'handling_mode' => 'ai',
            'handling_version' => 1,
            'handling_changed_by' => $this->user->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'action' => 'conversation.ai_handback',
            'auditable_id' => $conversation->id,
        ]);
    }

    public function test_handback_is_rejected_when_beezbud_is_disabled(): void
    {
        $conversation = $this->createConversation(
            mode: 'human'
        );

        $this->tenant->featureSettings()->updateOrCreate(
            ['feature_key' => 'beezbud'],
            ['is_enabled' => false]
        );

        $this->actingAs($this->user)
            ->postJson("/api/engage/conversations/{$conversation->id}/hand-back")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('beezbud');

        $this->assertDatabaseHas('engage_conversations', [
            'id' => $conversation->id,
            'handling_mode' => 'human',
            'handling_version' => 0,
        ]);
    }

    public function test_takeover_does_not_change_existing_assignment(): void
    {
        $conversation = $this->createConversation();

        $this->actingAs($this->user)
            ->postJson("/api/engage/conversations/{$conversation->id}/takeover")
            ->assertOk();

        $this->assertDatabaseHas('engage_conversations', [
            'id' => $conversation->id,
            'assigned_to' => null,
            'handling_mode' => 'human',
        ]);
    }

    public function test_user_cannot_take_over_another_tenants_conversation(): void
    {
        $otherTenant = Tenant::factory()->create();

        $conversation = $this->createConversation(
            $otherTenant->id
        );

        $this->actingAs($this->user)
            ->postJson("/api/engage/conversations/{$conversation->id}/takeover")
            ->assertNotFound();
    }

    public function test_repeated_takeover_does_not_increment_version_again(): void
    {
        $conversation = $this->createConversation();

        $this->actingAs($this->user)
            ->postJson("/api/engage/conversations/{$conversation->id}/takeover")
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson("/api/engage/conversations/{$conversation->id}/takeover")
            ->assertOk();

        $this->assertDatabaseHas('engage_conversations', [
            'id' => $conversation->id,
            'handling_mode' => 'human',
            'handling_version' => 1,
        ]);

        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_unauthenticated_user_cannot_take_over_conversation(): void
    {
        $conversation = $this->createConversation();

        $this->postJson("/api/engage/conversations/{$conversation->id}/takeover")
            ->assertUnauthorized();
    }
}
