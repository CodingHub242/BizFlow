<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        // Existing manual invoice lines must be linked to catalog items
        // before rolling this migration back.
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')
                ->nullable(false)
                ->change();
        });
    }
};