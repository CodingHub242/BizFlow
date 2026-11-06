<?php

namespace Database\Factories;

use App\Models\EngageConversation;
use App\Models\EngageMessage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class EngageMessageFactory extends Factory
{
    protected $model = EngageMessage::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'conversation_id' => EngageConversation::factory(),
            'provider_message_id' => null,
            'direction' => 'inbound',
            'message_type' => 'text',
            'body' => fake()->sentence(),
            'status' => 'received',
            'media' => null,
            'metadata' => null,
            'sent_at' => now(),
            'delivered_at' => null,
            'read_at' => null,
        ];
    }
}
