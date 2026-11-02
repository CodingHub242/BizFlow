<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_item_request_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('employee_item_request_id')
                ->constrained('employee_item_requests')
                ->cascadeOnDelete();

            $table->foreignId('catalog_item_id')
                ->constrained('catalog_items')
                ->restrictOnDelete();

            $table->decimal('quantity_requested', 15, 3);

            $table->decimal('quantity_approved', 15, 3)
                ->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique([
                'employee_item_request_id',
                'catalog_item_id',
            ]);

            $table->index([
                'tenant_id',
                'catalog_item_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_item_request_items');
    }
};