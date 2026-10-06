<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('platform_admins')
                ->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')
                ->nullable()
                ->constrained('platform_admins')
                ->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')
                ->nullable()
                ->constrained('platform_admins')
                ->nullOnDelete();
            $table->text('suspension_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropForeign(['suspended_by']);

            $table->dropColumn([
                'approved_at',
                'approved_by',
                'rejected_at',
                'rejected_by',
                'rejection_reason',
                'suspended_at',
                'suspended_by',
                'suspension_reason',
            ]);
        });
    }
};