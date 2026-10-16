<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_memo_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('credit_memo_id')
                ->constrained('credit_memos')
                ->cascadeOnDelete();

            $table->foreignId('catalog_item_id')
                ->nullable()
                ->constrained('catalog_items')
                ->nullOnDelete();

            $table->unsignedInteger('line_number')->default(1);

            // QuickBooks source line identifier, when available.
            $table->string('source_line_id')->nullable();

            $table->string('description')->nullable();

            $table->decimal('quantity', 15, 3)->nullable();
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);

            // Preserve QuickBooks line details for reconciliation.
            $table->json('source_data')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'credit_memo_id']);
            $table->index(['tenant_id', 'catalog_item_id']);

            $table->unique([
                'tenant_id',
                'credit_memo_id',
                'line_number',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_memo_items');
    }
};