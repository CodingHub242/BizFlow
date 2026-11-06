<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EngageContact;
use App\Models\EngageConversation;
use App\Models\EngageMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Database\Seeders\BizFlowRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EngageConversationApiTest extends TestCase
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

        $seeder = new BizFlowRolePermissionSeeder();
        $seeder->provisionTenantRoles($this->tenant->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->assignRole($this->user, 'Owner');
    }

    private function assignRole(User $user, string $roleName): void
    {
        setPermissionsTeamId($user->tenant_id);

        $role = Role::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('name', $roleName)
            ->firstOrFail();

        $user->assignRole($role);
    }

   
private function createConversation(
    ?int $tenantId = null,
    ?string $name = null,
    string $status = 'open'
): EngageConversation {
    $tenantId ??= $this->tenant->id;

    $phone = '024' . fake()->unique()->numerify('#######');

    $contact = EngageContact::factory()->create([
        'tenant_id' => $tenantId,
        'name' => $name ?? fake()->name(),
        'phone' => $phone,
        'normalized_phone' => '+233' . substr($phone, 1),
    ]);

    return EngageConversation::factory()->create([
        'tenant_id' => $tenantId,
        'contact_id' => $contact->id,
        'channel' => 'whatsapp',
        'status' => $status,
        'unread_count' => 2,
        'last_message_at' => now(),
    ]);
}


    public function test_authenticated_owner_can_list_conversations(): void
    {
       $conversation = $this->createConversation(name: 'Test Contact');

        $response = $this->actingAs($this->user)
            ->getJson('/api/engage/conversations');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.contact.name', 'Test Contact');
    }

    public function test_conversation_list_is_tenant_scoped(): void
    {
        $this->createConversation();

        $otherTenant = Tenant::factory()->create();

        $otherConversation = $this->createConversation(
            $otherTenant->id,
            'Other Tenant Contact'
        );

        $response = $this->actingAs($this->user)
            ->getJson('/api/engage/conversations');

        $response->assertOk()
            ->assertJsonMissing([
                'id' => $otherConversation->id,
            ]);
    }

    public function test_conversation_list_can_filter_by_status(): void
    {
        $open = $this->createConversation(status: 'open');
        $this->createConversation(status: 'closed');

        $response = $this->actingAs($this->user)
            ->getJson('/api/engage/conversations?status=open');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_conversation_list_can_search_contact_name(): void
    {
        $matching = $this->createConversation(
            name: 'Ama Mensah'
        );

        $this->createConversation(name: 'Kojo Asare');

        $response = $this->actingAs($this->user)
            ->getJson('/api/engage/conversations?search=Ama');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_conversation_list_supports_pagination(): void
    {
        $this->createConversation(name: 'Contact One');
        $this->createConversation(name: 'Contact Two');
        $this->createConversation(name: 'Contact Three');

        $response = $this->actingAs($this->user)
            ->getJson('/api/engage/conversations?per_page=2');

       $response->assertOk()
    ->assertJsonCount(2, 'data')
    ->assertJsonPath('meta.per_page', 2)
    ->assertJsonPath('meta.total', 3);
    }

    public function test_user_can_view_conversation_details(): void
    {
       $conversation = $this->createConversation(name: 'Test Contact');

        $response = $this->actingAs($this->user)
            ->getJson("/api/engage/conversations/{$conversation->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.contact.name', 'Test Contact');
    }

    public function test_user_cannot_view_another_tenants_conversation(): void
    {
        $otherTenant = Tenant::factory()->create();

        $conversation = $this->createConversation($otherTenant->id);

        $this->actingAs($this->user)
            ->getJson("/api/engage/conversations/{$conversation->id}")
            ->assertNotFound();
    }

    public function test_user_can_view_conversation_messages(): void
    {
        $conversation = $this->createConversation();

        $message = EngageMessage::factory()->create([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Hello Bizflow',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/engage/conversations/{$conversation->id}/messages");

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $message->id,
                'body' => 'Hello Bizflow',
            ]);
    }

    public function test_messages_are_scoped_to_the_conversation_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();

        $conversation = $this->createConversation($otherTenant->id);

        $this->actingAs($this->user)
            ->getJson("/api/engage/conversations/{$conversation->id}/messages")
            ->assertNotFound();
    }

    public function test_user_can_mark_conversation_as_read(): void
    {
        $conversation = $this->createConversation();

        $this->actingAs($this->user)
            ->postJson("/api/engage/conversations/{$conversation->id}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertDatabaseHas('engage_conversations', [
            'id' => $conversation->id,
            'unread_count' => 0,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_engage_conversations(): void
    {
        $this->getJson('/api/engage/conversations')
            ->assertUnauthorized();
    }
}
