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
    Schema::create('engage_whatsapp_accounts', function (Blueprint $table) {
        $table->id();

        $table->foreignId('tenant_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->string('business_id')->nullable();
        $table->string('waba_id');
        $table->string('name')->nullable();

        // Store encrypted access tokens.
        $table->text('access_token')->nullable();
        $table->timestamp('token_expires_at')->nullable();

        $table->string('status')->default('active');

        $table->foreignId('connected_by')
            ->nullable()
            ->constrained('users')
            ->nullOnDelete();

        $table->timestamps();

        $table->unique(
            ['tenant_id', 'waba_id'],
            'engage_whatsapp_accounts_tenant_waba_unique'
        );

        $table->index(['tenant_id', 'status']);
    });
}

public function down(): void
{
    Schema::dropIfExists('engage_whatsapp_accounts');
}
};
