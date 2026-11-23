<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_conversations', function (Blueprint $table) {
            $table->string('handling_mode')
                ->default('human')
                ->after('status');

            $table->foreignId('handling_changed_by')
                ->nullable()
                ->after('handling_mode')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('handling_changed_at')
                ->nullable()
                ->after('handling_changed_by');

            $table->unsignedInteger('handling_version')
                ->default(0)
                ->after('handling_changed_at');

            $table->index(
                ['tenant_id', 'handling_mode'],
                'engage_conversations_handling_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('engage_conversations', function (Blueprint $table) {
            $table->dropIndex('engage_conversations_handling_index');

            $table->dropConstrainedForeignId('handling_changed_by');

            $table->dropColumn([
                'handling_mode',
                'handling_changed_at',
                'handling_version',
            ]);
        });
    }
};
