<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 100);
            $table->decimal('quantity_received', 15, 3);
            $table->decimal('quantity_remaining', 15, 3);
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->decimal('selling_price', 15, 2)->nullable();
            $table->timestamp('received_at');
            $table->string('source_type', 40);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('origin_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'catalog_item_id', 'batch_number'], 'inventory_batch_number_unique');
            $table->index(['tenant_id', 'branch_id', 'catalog_item_id', 'received_at'], 'inventory_batch_fifo_idx');
        });

        Schema::create('inventory_batch_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_batch_id')->constrained('inventory_batches')->restrictOnDelete();
            $table->string('type', 40);
            $table->decimal('quantity', 15, 3);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'inventory_batch_id']);
        });

        Schema::create('inventory_batch_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_batch_id')->constrained('inventory_batches')->restrictOnDelete();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_item_id')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost_snapshot', 15, 2)->nullable();
            $table->decimal('unit_selling_price_snapshot', 15, 2);
            $table->timestamps();
            $table->index(['tenant_id', 'source_type', 'source_id'], 'inventory_batch_allocation_source_idx');
            $table->index(['tenant_id', 'inventory_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_batch_allocations');
        Schema::dropIfExists('inventory_batch_movements');
        Schema::dropIfExists('inventory_batches');
    }
};
