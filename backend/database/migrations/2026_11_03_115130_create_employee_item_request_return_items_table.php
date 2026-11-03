<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_item_request_return_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id');

            $table->foreignId('employee_item_request_return_id')
                ->constrained('employee_item_request_returns')
                ->cascadeOnDelete();

            $table->foreignId('employee_item_request_issue_item_id')
                ->constrained('employee_item_request_issue_items')
                ->restrictOnDelete();

            // The original batch from which the item was issued.
            $table->foreignId('inventory_batch_id')
                ->constrained('inventory_batches')
                ->restrictOnDelete();

            $table->decimal('quantity_returned', 15, 3);

            // restockable, damaged, unusable
            $table->string('return_condition', 30);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(
                ['tenant_id', 'employee_item_request_issue_item_id'],
                'eir_return_items_issue_item_index'
            );

            $table->index(
                ['tenant_id', 'inventory_batch_id'],
                'eir_return_items_batch_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_item_request_return_items');
    }
};