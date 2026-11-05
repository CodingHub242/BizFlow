<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engage_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('conversation_id')
                ->constrained('engage_conversations')
                ->cascadeOnDelete();

            $table->string('provider_message_id')->nullable();

            $table->string('direction');
            // inbound, outbound

            $table->string('message_type')->default('text');
            // text, image, document, audio, video, template, etc.

            $table->longText('body')->nullable();

            $table->string('status')->default('received');
            // received, queued, sent, delivered, read, failed

            $table->json('media')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id']);
            $table->index(['conversation_id', 'created_at']);

            $table->unique(
                ['tenant_id', 'provider_message_id'],
                'engage_messages_provider_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engage_messages');
    }
};