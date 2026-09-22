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
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('received_quantity', 15, 3)
                ->default(0)
                ->after('quantity');

            $table->index([
                'tenant_id',
                'purchase_id',
                'received_quantity',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropIndex([
                'purchase_items_tenant_id_purchase_id_received_quantity_index',
            ]);

            $table->dropColumn('received_quantity');
        });
    }
};
