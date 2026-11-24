<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_messages', function (Blueprint $table) {
            $table->uuid('ai_processing_token')
                ->nullable()
                ->after('ai_processing_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('engage_messages', function (Blueprint $table) {
            $table->dropColumn('ai_processing_token');
        });
    }
};
