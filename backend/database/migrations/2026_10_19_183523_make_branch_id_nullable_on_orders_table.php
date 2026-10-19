<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        // Existing branchless orders must be assigned a branch
        // before this column can safely become NOT NULL again.
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable(false)
                ->change();
        });
    }
};