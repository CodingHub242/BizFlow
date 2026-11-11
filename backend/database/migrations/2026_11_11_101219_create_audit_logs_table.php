<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Keep the historical tenant ID even if the tenant is deleted.
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            // Keep the historical actor name/email if the user is deleted.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();

            $table->string('action', 100);

            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->text('url')->nullable();
            $table->string('http_method', 10)->nullable();

            $table->string('request_id', 100)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['tenant_id', 'created_at'],
                'audit_logs_tenant_created_index'
            );

            $table->index(
                ['auditable_type', 'auditable_id'],
                'audit_logs_auditable_index'
            );

            $table->index(
                ['user_id', 'created_at'],
                'audit_logs_user_created_index'
            );

            $table->index(
                ['tenant_id', 'action'],
                'audit_logs_tenant_action_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};