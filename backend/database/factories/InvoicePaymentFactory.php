<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoicePayment>
 */
class InvoicePaymentFactory extends Factory
{
    protected $model = InvoicePayment::class;

    public function definition(): array
    {
        $invoice = Invoice::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $invoice->tenant_id,
        ]);

        return [
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'recorded_by' => $user->id,
            'amount' => 1000,
            'method' => 'cash',
            'reference' => fake()->unique()->bothify('PAY-####??'),
            'notes' => null,
            'paid_at' => now(),
        ];
    }
}