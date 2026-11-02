<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_item_requests', function (Blueprint $table) {
            $table->foreignId('rejected_by_user_id')
                ->nullable()
                ->after('approved_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();

            $table->foreignId('cancelled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_item_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by_user_id');
            $table->dropColumn('rejected_at');

            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn('cancelled_at');
        });
    }
};
