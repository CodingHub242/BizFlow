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
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->unique(
                ['tenant_id', 'order_item_id'],
                'fulfillment_requests_tenant_order_item_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->dropUnique(
                'fulfillment_requests_tenant_order_item_unique'
            );
        });
    }
};
