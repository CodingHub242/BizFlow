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
        Schema::table('migration_sessions', function (Blueprint $table) {
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('migration_sessions', function (Blueprint $table) {
           $table->dropColumn([
                'original_filename',
                'file_path',
                'file_size',
                'mime_type',
            ]);
        });
    }
};
