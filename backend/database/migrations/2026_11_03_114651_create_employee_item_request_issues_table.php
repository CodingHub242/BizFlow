<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_item_request_issues', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('employee_item_request_id')
                ->constrained('employee_item_requests')
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('issue_number');
            $table->foreignId('issued_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('issued_at');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'issue_number'],
                'eir_issues_tenant_number_unique'
            );

            $table->index(
                ['tenant_id', 'employee_item_request_id'],
                'eir_issues_request_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_item_request_issues');
    }
};