<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_import_batches', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('file_name');
            $table->string('file_path', 500);
            $table->string('file_type', 10);

            $table->string('status', 30)->default('previewed');

            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);

            $table->json('preview_rows')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('imported_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_import_batches');
    }
};