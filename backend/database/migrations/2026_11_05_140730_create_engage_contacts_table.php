<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engage_contacts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Optional link to the Bizflow Core customer.
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            $table->string('name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('normalized_phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('company_name')->nullable();

            $table->string('source')->default('manual');
            // whatsapp, manual, import, website, etc.

            $table->string('lifecycle_status')->default('prospect');
            // prospect, lead, customer

            $table->string('status')->default('active');
            // active, inactive, archived

            $table->text('notes')->nullable();

            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['tenant_id', 'normalized_phone'],
                'engage_contacts_tenant_phone_unique'
            );

            $table->index(['tenant_id', 'lifecycle_status']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engage_contacts');
    }
};