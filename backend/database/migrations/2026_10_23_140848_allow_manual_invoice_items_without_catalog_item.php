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
        // Do not roll back while any invoice line has a null catalog_item_id.
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')
                ->nullable(false)
                ->change();
        });
    }
};