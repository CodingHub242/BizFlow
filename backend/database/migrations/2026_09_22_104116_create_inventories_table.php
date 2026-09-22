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
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->foreignId('catalog_item_id')
                ->constrained('catalog_items')
                ->cascadeOnDelete();

            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('reorder_level', 15, 3)->default(0);

            $table->timestamps();

            $table->unique([
                'tenant_id',
                'branch_id',
                'catalog_item_id',
            ]);

            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'catalog_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
