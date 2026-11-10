<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EngageMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,

            'provider_message_id' => $this->provider_message_id,

            'direction' => $this->direction,
            'message_type' => $this->message_type,
            'body' => $this->body,
            'status' => $this->status,

            'media' => $this->media,
            'metadata' => $this->metadata,

            'received_at' => $this->received_at,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'read_at' => $this->read_at,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
