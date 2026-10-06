<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->index('status');
        });

        DB::table('tenants')
            ->where('status', 'active')
            ->update([
                'status' => 'approved',
            ]);
    }

    public function down(): void
    {
        DB::table('tenants')
            ->where('status', 'approved')
            ->update([
                'status' => 'active',
            ]);

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};