<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->foreignId('catalog_item_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        // Remove custom descriptions, but retain nullable catalog_item_id.
        // Existing custom invoice lines may have catalog_item_id = null;
        // making this column NOT NULL could destroy rollback safety.
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
