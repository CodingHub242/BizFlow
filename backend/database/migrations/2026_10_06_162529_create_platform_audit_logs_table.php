<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('platform_admin_id')
                ->constrained('platform_admins')
                ->cascadeOnDelete();

            $table->string('action');

            $table->string('target_type');
            $table->unsignedBigInteger('target_id');

            $table->text('reason')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(
                ['target_type', 'target_id']
            );

            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};