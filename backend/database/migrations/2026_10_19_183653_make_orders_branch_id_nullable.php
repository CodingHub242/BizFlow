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
        // Do not roll back to NOT NULL while branchless orders exist.
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable(false)
                ->change();
        });
    }
};