<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_item_request_issue_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id');

            $table->foreignId('employee_item_request_issue_id')
                ->constrained('employee_item_request_issues')
                ->cascadeOnDelete();

            $table->foreignId('employee_item_request_item_id')
                ->constrained('employee_item_request_items')
                ->restrictOnDelete();

            $table->foreignId('catalog_item_id')
                ->constrained('catalog_items')
                ->restrictOnDelete();

            $table->decimal('quantity_issued', 15, 3);

            // Snapshot whether this item required inventory at issuance.
            $table->boolean('tracks_inventory')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(
                ['tenant_id', 'employee_item_request_item_id'],
                'eir_issue_items_request_item_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_item_request_issue_items');
    }
};