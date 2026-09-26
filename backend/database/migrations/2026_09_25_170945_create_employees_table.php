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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            $table->string('employee_number');
            $table->string('phone')->nullable();
            $table->string('job_title')->nullable();

            $table->string('employment_status')
                ->default('active');

            $table->date('hired_at')->nullable();

            $table->timestamps();

            $table->unique([
                'tenant_id',
                'user_id',
            ]);

            $table->unique([
                'tenant_id',
                'employee_number',
            ]);

            $table->index([
                'tenant_id',
                'branch_id',
            ]);

            $table->index([
                'tenant_id',
                'employment_status',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
