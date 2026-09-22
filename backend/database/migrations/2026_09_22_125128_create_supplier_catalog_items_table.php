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
        Schema::create('supplier_catalog_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->cascadeOnDelete();

            $table->foreignId('catalog_item_id')
                ->constrained('catalog_items')
                ->cascadeOnDelete();

            $table->string('supplier_sku')->nullable();

            $table->decimal('purchase_price', 15, 2)->nullable();

            $table->decimal('minimum_order_quantity', 15, 3)
                ->default(1);

            $table->unsignedInteger('lead_time_days')->nullable();

            $table->boolean('is_preferred')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique([
                'tenant_id',
                'supplier_id',
                'catalog_item_id',
            ]);

            $table->index([
                'tenant_id',
                'supplier_id',
            ]);

            $table->index([
                'tenant_id',
                'catalog_item_id',
            ]);

            $table->index([
                'tenant_id',
                'is_preferred',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_catalog_items');
    }
};
