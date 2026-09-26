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
        Schema::create('migration_validation_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('migration_session_id')
                ->constrained('migration_sessions')
                ->cascadeOnDelete();

            $table->string('entity_type');

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);

            $table->json('errors')->nullable();

            $table->timestamps();

            $table->unique([
                'tenant_id',
                'migration_session_id',
                'entity_type',
            ]);

            $table->index([
                'tenant_id',
                'entity_type',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('migration_validation_results');
    }
};
