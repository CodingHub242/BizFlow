<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_item_can_be_created(): void
    {
        $tenant = Tenant::factory()->create();

        $invoice = Invoice::factory()
            ->for($tenant)
            ->create();

        $catalogItem = CatalogItem::factory()
            ->for($tenant)
            ->create();

        $item = InvoiceItem::create([
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 2,
            'unit_price' => 500,
            'discount' => 50,
            'tax' => 90,
            'line_total' => 1040,
        ]);

        $this->assertDatabaseHas('invoice_items', [
            'id' => $item->id,
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'catalog_item_id' => $catalogItem->id,
        ]);
    }

    public function test_invoice_item_belongs_to_invoice(): void
    {
        $tenant = Tenant::factory()->create();

        $invoice = Invoice::factory()
            ->for($tenant)
            ->create();

        $catalogItem = CatalogItem::factory()
            ->for($tenant)
            ->create();

        $item = InvoiceItem::create([
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 2,
            'unit_price' => 500,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 1000,
        ]);

        $this->assertTrue($item->invoice->is($invoice));
    }

    public function test_invoice_item_belongs_to_catalog_item(): void
    {
        $tenant = Tenant::factory()->create();

        $invoice = Invoice::factory()
            ->for($tenant)
            ->create();

        $catalogItem = CatalogItem::factory()
            ->for($tenant)
            ->create();

        $item = InvoiceItem::create([
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 2,
            'unit_price' => 500,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 1000,
        ]);

        $this->assertTrue($item->catalogItem->is($catalogItem));
    }

    public function test_invoice_has_many_items(): void
    {
        $tenant = Tenant::factory()->create();

        $invoice = Invoice::factory()
            ->for($tenant)
            ->create();

        $catalogItem = CatalogItem::factory()
            ->for($tenant)
            ->create();

        $item = InvoiceItem::create([
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 2,
            'unit_price' => 500,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 1000,
        ]);

        $this->assertTrue(
            $invoice->items->contains($item)
        );
    }

    public function test_invoice_item_values_are_cast_correctly(): void
    {
        $tenant = Tenant::factory()->create();

        $invoice = Invoice::factory()
            ->for($tenant)
            ->create();

        $catalogItem = CatalogItem::factory()
            ->for($tenant)
            ->create();

        $item = InvoiceItem::create([
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 2.500,
            'unit_price' => 125.50,
            'discount' => 10.25,
            'tax' => 23.05,
            'line_total' => 327.05,
        ]);

        $item->refresh();

        $this->assertSame('2.500', $item->quantity);
        $this->assertSame('125.50', $item->unit_price);
        $this->assertSame('10.25', $item->discount);
        $this->assertSame('23.05', $item->tax);
        $this->assertSame('327.05', $item->line_total);
    }

    public function test_invoice_item_cannot_reference_invoice_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $invoiceA = Invoice::factory()
            ->for($tenantA)
            ->create();

        $invoiceB = Invoice::factory()
            ->for($tenantB)
            ->create();

        $catalogItemA = CatalogItem::factory()
            ->for($tenantA)
            ->create();

        $item = InvoiceItem::create([
            'tenant_id' => $tenantA->id,
            'invoice_id' => $invoiceA->id,
            'catalog_item_id' => $catalogItemA->id,
            'quantity' => 1,
            'unit_price' => 500,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 500,
        ]);

        $this->assertEquals($tenantA->id, $item->tenant_id);
        $this->assertEquals($invoiceA->id, $item->invoice_id);

        $this->assertNotEquals($tenantB->id, $item->tenant_id);
        $this->assertNotEquals($invoiceB->id, $item->invoice_id);
    }

    public function test_invoice_item_cannot_reference_catalog_item_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $invoiceA = Invoice::factory()
            ->for($tenantA)
            ->create();

        $catalogItemA = CatalogItem::factory()
            ->for($tenantA)
            ->create();

        $catalogItemB = CatalogItem::factory()
            ->for($tenantB)
            ->create();

        $item = InvoiceItem::create([
            'tenant_id' => $tenantA->id,
            'invoice_id' => $invoiceA->id,
            'catalog_item_id' => $catalogItemA->id,
            'quantity' => 1,
            'unit_price' => 500,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 500,
        ]);

        $this->assertEquals($tenantA->id, $item->tenant_id);
        $this->assertEquals($catalogItemA->id, $item->catalog_item_id);

        $this->assertNotEquals($catalogItemB->id, $item->catalog_item_id);
        $this->assertNotEquals($tenantB->id, $item->tenant_id);
    }

    public function test_invoice_item_tenant_matches_invoice_and_catalog_item(): void
    {
        $tenant = Tenant::factory()->create();

        $invoice = Invoice::factory()
            ->for($tenant)
            ->create();

        $catalogItem = CatalogItem::factory()
            ->for($tenant)
            ->create();

        $item = InvoiceItem::create([
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 2,
            'unit_price' => 750,
            'discount' => 50,
            'tax' => 70,
            'line_total' => 1520,
        ]);

        $this->assertEquals($tenant->id, $item->tenant_id);
        $this->assertEquals($tenant->id, $item->invoice->tenant_id);
        $this->assertEquals($tenant->id, $item->catalogItem->tenant_id);
    }
}