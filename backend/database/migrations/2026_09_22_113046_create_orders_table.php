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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            // Customer will be connected once the Customers module exists.
          $table->foreignId('customer_id')
            ->nullable();

            $table->string('order_number');

            $table->string('status')->default('draft');

            $table->string('payment_status')->default('unpaid');

            $table->decimal('subtotal', 15, 2)->default(0);

            $table->decimal('discount', 15, 2)->default(0);

            $table->decimal('tax', 15, 2)->default(0);

            $table->decimal('total', 15, 2)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->softDeletes();

            $table->unique([
                'tenant_id',
                'order_number',
            ]);

            $table->index([
                'tenant_id',
                'status',
            ]);

            $table->index([
                'tenant_id',
                'payment_status',
            ]);

            $table->index([
                'tenant_id',
                'branch_id',
            ]);

            $table->index([
                'tenant_id',
                'customer_id',
            ]);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
