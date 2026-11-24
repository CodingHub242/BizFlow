<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_messages', function (Blueprint $table) {
            $table->string('ai_processing_status')
                ->nullable()
                ->after('dispatched_at');

            $table->timestamp('ai_processing_started_at')
                ->nullable()
                ->after('ai_processing_status');

            $table->foreignId('ai_response_message_id')
                ->nullable()
                ->after('ai_processing_started_at')
                ->constrained('engage_messages')
                ->nullOnDelete();

            $table->unique(
                'ai_response_message_id',
                'engage_messages_ai_response_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('engage_messages', function (Blueprint $table) {
            $table->dropUnique(
                'engage_messages_ai_response_unique'
            );

            $table->dropConstrainedForeignId('ai_response_message_id');

            $table->dropColumn([
                'ai_processing_status',
                'ai_processing_started_at',
            ]);
        });
    }
};
