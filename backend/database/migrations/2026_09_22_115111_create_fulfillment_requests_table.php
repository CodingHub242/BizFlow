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
        Schema::create('fulfillment_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->foreignId('order_item_id')
                ->constrained('order_items')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->foreignId('catalog_item_id')
                ->constrained('catalog_items')
                ->restrictOnDelete();

            $table->decimal('requested_quantity', 15, 3);

            $table->decimal('available_quantity', 15, 3);

            $table->decimal('fulfilled_quantity', 15, 3)->default(0);

            $table->decimal('shortfall_quantity', 15, 3);

            $table->string('status')->default('pending');

            $table->string('source_type')->nullable();

            // Used when stock is being sourced from another BizFlow branch.
            $table->foreignId('source_branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            // Supplier will be connected once the Suppliers module exists.
            $table->unsignedBigInteger('source_supplier_id')->nullable();

            // Staff member responsible for resolving the shortage.
            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('expected_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index([
                'tenant_id',
                'status',
            ]);

            $table->index([
                'tenant_id',
                'order_id',
            ]);

            $table->index([
                'tenant_id',
                'order_item_id',
            ]);

            $table->index([
                'tenant_id',
                'catalog_item_id',
            ]);

            $table->index([
                'tenant_id',
                'branch_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fulfillment_requests');
    }
};
