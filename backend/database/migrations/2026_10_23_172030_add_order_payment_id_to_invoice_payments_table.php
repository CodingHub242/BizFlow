<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->foreignId('order_payment_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('order_payments')
                ->nullOnDelete();

            $table->unique(
                ['tenant_id', 'order_payment_id'],
                'invoice_payments_tenant_order_payment_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropUnique(
                'invoice_payments_tenant_order_payment_unique'
            );

            $table->dropConstrainedForeignId('order_payment_id');
        });
    }
};