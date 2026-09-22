<?php

namespace Tests\Feature;

use App\Models\InvoicePayment;
use App\Models\InvoicePaymentReversal;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePaymentReversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_payment_reversal_belongs_to_payment_and_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $payment = InvoicePayment::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $reversal = InvoicePaymentReversal::create([
            'tenant_id' => $tenant->id,
            'invoice_payment_id' => $payment->id,
            'recorded_by' => $user->id,
            'amount' => 1000,
            'reason' => 'Customer refund',
            'reversed_at' => now(),
        ]);

        $this->assertEquals($tenant->id, $reversal->tenant_id);
        $this->assertEquals($payment->id, $reversal->invoice_payment_id);
        $this->assertEquals($user->id, $reversal->recorded_by);
        $this->assertTrue($reversal->invoicePayment->is($payment));
        $this->assertTrue($reversal->tenant->is($tenant));
        $this->assertTrue($reversal->recordedBy->is($user));
    }

    public function test_invoice_payment_cannot_be_reversed_twice(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $payment = InvoicePayment::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        InvoicePaymentReversal::create([
            'tenant_id' => $tenant->id,
            'invoice_payment_id' => $payment->id,
            'recorded_by' => $user->id,
            'amount' => 1000,
            'reason' => 'Customer refund',
            'reversed_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        InvoicePaymentReversal::create([
            'tenant_id' => $tenant->id,
            'invoice_payment_id' => $payment->id,
            'recorded_by' => $user->id,
            'amount' => 1000,
            'reason' => 'Duplicate refund',
            'reversed_at' => now(),
        ]);
    }
}