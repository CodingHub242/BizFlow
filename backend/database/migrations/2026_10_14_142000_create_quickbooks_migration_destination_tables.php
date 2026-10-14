<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('account_number')->nullable();
            $table->string('name');
            $table->string('account_type');
            $table->string('detail_type')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_sub_account')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Do not make account_number unique: QuickBooks data may omit it.
            $table->index(['tenant_id', 'account_type']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'parent_id']);
        });

        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('bill_number')->nullable();
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('status', 40)->default('open');
            $table->string('payment_status', 40)->default('unpaid');
            $table->string('source_status')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            // Source DocNumber can be missing or duplicated. Idempotency is based
            // on QuickBooks Id in quickbooks_import_mappings, not this field.
            $table->index(['tenant_id', 'supplier_id']);
            $table->index(['tenant_id', 'bill_date']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'payment_status']);
        });

        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->foreignId('chart_of_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('line_type', 60)->nullable();
            $table->string('source_line_id', 128)->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 15, 3)->nullable();
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('line_order')->default(0);
            $table->jsonb('source_data')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'bill_id']);
        });

        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('estimate_number')->nullable();
            $table->date('estimate_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->string('status', 40)->default('draft');
            $table->string('source_status')->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            // No unique constraint on estimate_number: QBO DocNumber is optional
            // and is not a safe external identity. See source mapping table.
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'estimate_date']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('estimate_id')->constrained('estimates')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->string('source_line_id', 128)->nullable();
            $table->string('line_type', 60)->nullable();
            $table->string('item_name')->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 15, 3)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->unsignedInteger('line_order')->default(0);
            $table->jsonb('source_data')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'estimate_id']);
        });

        Schema::create('quickbooks_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quickbooks_connection_id')
                ->constrained('quickbooks_connections')
                ->cascadeOnDelete();
            $table->string('entity_type', 60);
            $table->string('quickbooks_id', 128);
            $table->string('local_type', 120);
            $table->unsignedBigInteger('local_id');
            $table->string('payload_hash', 64)->nullable();
            $table->timestampTz('imported_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            // Connection + entity + QBO Id is the idempotency key. This avoids
            // collisions when document numbers are missing or duplicated, and
            // allows separate QuickBooks companies to reuse the same numeric IDs.
            $table->unique(
                ['tenant_id', 'quickbooks_connection_id', 'entity_type', 'quickbooks_id'],
                'qb_import_map_source_unique'
            );
            $table->index(['tenant_id', 'local_type', 'local_id'], 'qb_import_map_local_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_import_mappings');
        Schema::dropIfExists('estimate_items');
        Schema::dropIfExists('estimates');
        Schema::dropIfExists('bill_items');
        Schema::dropIfExists('bills');
        Schema::dropIfExists('chart_of_accounts');
    }
};
