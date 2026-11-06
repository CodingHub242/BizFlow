<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EngageConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,

            'channel' => $this->channel,
            'external_conversation_id' => $this->external_conversation_id,
            'status' => $this->status,

            'assigned_to' => $this->assigned_to,
            'unread_count' => $this->unread_count,

            'last_message_at' => $this->last_message_at,
            'last_inbound_at' => $this->last_inbound_at,

           'contact' => $this->whenLoaded(
                'contact',
                fn () => $this->contact
                    ? new EngageContactResource($this->contact)
                    : null
            ),

            'assignee' => $this->whenLoaded(
                'assignee',
                fn () => $this->assignee
                    ? [
                        'id' => $this->assignee->id,
                        'name' => $this->assignee->name,
                    ]
                    : null
            ),

            'latest_message' => $this->whenLoaded(
                'latestMessage',
                fn () => $this->latestMessage
                    ? new EngageMessageResource($this->latestMessage)
                    : null
            ),

            'messages_count' => $this->whenCounted('messages'),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}