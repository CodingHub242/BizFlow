<?php

namespace Database\Factories;

use App\Models\EngageContact;
use App\Models\EngageConversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EngageConversationFactory extends Factory
{
    protected $model = EngageConversation::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contact_id' => EngageContact::factory(),
            'channel' => 'whatsapp',
            'external_conversation_id' => null,
            'status' => 'open',
            'assigned_to' => null,
            'unread_count' => 0,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ];
    }
}
