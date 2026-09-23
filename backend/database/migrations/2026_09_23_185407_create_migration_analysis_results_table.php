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
        Schema::create('migration_analysis_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('migration_session_id')
                ->constrained('migration_sessions')
                ->cascadeOnDelete();

            $table->string('entity_type')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->json('headers')->nullable();
            $table->json('sample_rows')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'migration_session_id']);
            $table->index(['tenant_id', 'entity_type']);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('migration_analysis_results');
    }
};
