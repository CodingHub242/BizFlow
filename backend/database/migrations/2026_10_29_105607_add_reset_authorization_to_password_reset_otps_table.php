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
    Schema::table('password_reset_otps', function (Blueprint $table) {
        $table->string('reset_token_hash')->nullable();
        $table->timestamp('reset_token_expires_at')->nullable();
    });
}

public function down(): void
{
    Schema::table('password_reset_otps', function (Blueprint $table) {
        $table->dropColumn([
            'reset_token_hash',
            'reset_token_expires_at',
        ]);
    });
}
};
