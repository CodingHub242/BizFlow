<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engage_conversations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('contact_id')
                ->constrained('engage_contacts')
                ->cascadeOnDelete();

            $table->string('channel')->default('whatsapp');

            $table->string('external_conversation_id')->nullable();

            $table->string('status')->default('open');
            // open, closed, pending

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
                

            $table->unsignedInteger('unread_count')->default(0);

            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'last_message_at']);
            $table->index(['tenant_id', 'contact_id']);

            $table->unique(
                ['tenant_id', 'channel', 'external_conversation_id'],
                'engage_conversations_external_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engage_conversations');
    }
};