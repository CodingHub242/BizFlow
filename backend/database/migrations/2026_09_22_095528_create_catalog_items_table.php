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
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            $table->string('type');
            $table->string('name');
            $table->string('sku')->nullable();

            $table->text('description')->nullable();

            $table->string('unit')->nullable();

            $table->decimal('cost_price', 15, 2)->nullable();
            $table->decimal('selling_price', 15, 2);

            $table->decimal('tax_rate', 5, 2)->nullable();

            $table->boolean('track_inventory')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'sku']);

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
