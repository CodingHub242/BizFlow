<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_memo_applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('credit_memo_id')
                ->constrained('credit_memos')
                ->cascadeOnDelete();

            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->restrictOnDelete();

            $table->decimal('amount_applied', 15, 2);

            $table->timestamp('applied_at')->nullable();

            // QuickBooks transaction ID referenced by LinkedTxn.
            $table->string('quickbooks_linked_txn_id')->nullable();

            // Preserve LinkedTxn details and source application metadata.
            $table->json('source_data')->nullable();

            $table->timestamps();

            $table->unique([
                'tenant_id',
                'credit_memo_id',
                'invoice_id',
            ]);

            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'credit_memo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_memo_applications');
    }
};