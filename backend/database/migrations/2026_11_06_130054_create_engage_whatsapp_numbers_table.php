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
    Schema::create('engage_whatsapp_numbers', function (Blueprint $table) {
        $table->id();

        $table->foreignId('tenant_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->foreignId('whatsapp_account_id')
            ->constrained('engage_whatsapp_accounts')
            ->cascadeOnDelete();

        $table->string('phone_number_id')->unique();
        $table->string('display_phone_number')->nullable();
        $table->string('verified_name')->nullable();

        $table->string('quality_rating')->nullable();
        $table->string('messaging_limit_tier')->nullable();

        $table->boolean('is_default')->default(false);
        $table->string('status')->default('active');

        $table->timestamps();

        $table->index(['tenant_id', 'status']);
        $table->index(['whatsapp_account_id', 'status']);
    });
}

public function down(): void
{
    Schema::dropIfExists('engage_whatsapp_numbers');
}
};
