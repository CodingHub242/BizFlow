<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('engage_conversations', function (Blueprint $table) {
        $table->foreignId('whatsapp_number_id')
            ->nullable()
            ->after('contact_id')
            ->constrained('engage_whatsapp_numbers')
            ->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('engage_conversations', function (Blueprint $table) {
        $table->dropConstrainedForeignId('whatsapp_number_id');
    });
}
};
