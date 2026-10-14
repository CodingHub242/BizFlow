<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quickbooks_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('realm_id', 128);
            $table->string('company_name')->nullable();
            $table->string('connection_status', 40)->default('connected');
            // Encrypted Laravel casts store ciphertext in these TEXT columns.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestampTz('access_token_expires_at')->nullable();
            $table->timestampTz('refresh_token_expires_at')->nullable();
            $table->jsonb('scopes')->nullable();
            $table->timestampTz('connected_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'realm_id']);
            $table->index(['tenant_id', 'connection_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_connections');
    }
};
