<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_item_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            $table->foreignId('requested_by_employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->string('request_number');

            $table->string('status')
                ->default('pending');

            $table->text('purpose')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();

            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->unique([
                'tenant_id',
                'request_number',
            ]);

            $table->index([
                'tenant_id',
                'status',
            ]);

            $table->index([
                'tenant_id',
                'requested_by_employee_id',
            ]);

            $table->index([
                'tenant_id',
                'branch_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_item_requests');
    }
};