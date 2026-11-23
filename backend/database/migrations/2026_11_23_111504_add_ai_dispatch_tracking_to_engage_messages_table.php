<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_messages', function (Blueprint $table) {
            $table->string('sender_type')->nullable();

            $table->unsignedInteger('handling_version')->nullable();

            $table->timestamp('dispatched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('engage_messages', function (Blueprint $table) {
            $table->dropColumn([
                'sender_type',
                'handling_version',
                'dispatched_at',
            ]);
        });
    }
};
