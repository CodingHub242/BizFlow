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
       Schema::create('invoice_payment_reversals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('invoice_payment_id')
                ->constrained('invoice_payments')
                ->restrictOnDelete();

            $table->foreignId('recorded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->decimal('amount', 15, 2);

            $table->string('reason');

            $table->text('notes')->nullable();

            $table->timestamp('reversed_at');

            $table->timestamps();

            $table->index(['tenant_id', 'invoice_payment_id']);
            $table->index(['tenant_id', 'reversed_at']);

            $table->unique([
                'tenant_id',
                'invoice_payment_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_payment_reversals');
    }
};
