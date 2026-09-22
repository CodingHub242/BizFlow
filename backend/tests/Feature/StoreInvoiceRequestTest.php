<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StoreInvoiceRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_requires_at_least_one_item(): void
    {
        $tenant = Tenant::factory()->create();

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $request = StoreInvoiceRequest::create('/api/invoices', 'POST', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-001',
            'items' => [],
        ]);

        $validator = Validator::make(
            $request->all(),
            $request->rules()
        );

        $this->assertTrue($validator->fails());

        $this->assertArrayHasKey(
            'items',
            $validator->errors()->toArray()
        );
    }

    public function test_invoice_number_is_required(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $request = StoreInvoiceRequest::create('/api/invoices', 'POST', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'items' => [
            [
                'catalog_item_id' => 1,
                'quantity' => 1,
                'unit_price' => 1000,
            ],
        ],
    ]);

    $validator = Validator::make(
        $request->all(),
        $request->rules()
    );

    $this->assertTrue($validator->fails());

    $this->assertArrayHasKey(
        'invoice_number',
        $validator->errors()->toArray()
    );
}

public function test_branch_is_required(): void
{
    $tenant = Tenant::factory()->create();

    $request = StoreInvoiceRequest::create('/api/invoices', 'POST', [
        'tenant_id' => $tenant->id,
        'invoice_number' => 'INV-001',
        'items' => [
            [
                'catalog_item_id' => 1,
                'quantity' => 1,
                'unit_price' => 1000,
            ],
        ],
    ]);

    $validator = Validator::make(
        $request->all(),
        $request->rules()
    );

    $this->assertTrue($validator->fails());

    $this->assertArrayHasKey(
        'branch_id',
        $validator->errors()->toArray()
    );
}

public function test_invoice_item_quantity_must_be_greater_than_zero(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $request = StoreInvoiceRequest::create('/api/invoices', 'POST', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'invoice_number' => 'INV-001',
        'items' => [
            [
                'catalog_item_id' => 1,
                'quantity' => 0,
                'unit_price' => 1000,
            ],
        ],
    ]);

    $validator = Validator::make(
        $request->all(),
        $request->rules()
    );

    $this->assertTrue($validator->fails());

    $this->assertArrayHasKey(
        'items.0.quantity',
        $validator->errors()->toArray()
    );
}

public function test_invoice_item_money_values_cannot_be_negative(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $request = StoreInvoiceRequest::create('/api/invoices', 'POST', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'invoice_number' => 'INV-001',
        'items' => [
            [
                'catalog_item_id' => 1,
                'quantity' => 1,
                'unit_price' => -100,
                'discount' => -50,
                'tax' => -20,
            ],
        ],
    ]);

    $validator = Validator::make(
        $request->all(),
        $request->rules()
    );

    $this->assertTrue($validator->fails());

    $errors = $validator->errors()->toArray();

    $this->assertArrayHasKey('items.0.unit_price', $errors);
    $this->assertArrayHasKey('items.0.discount', $errors);
    $this->assertArrayHasKey('items.0.tax', $errors);
}

public function test_invoice_optional_fields_can_be_omitted(): void
{
    $tenant = Tenant::factory()->create();

    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $catalogItem = CatalogItem::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $request = StoreInvoiceRequest::create('/api/invoices', 'POST', [
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'invoice_number' => 'INV-001',
        'items' => [
            [
                'catalog_item_id' => $catalogItem->id,
                'quantity' => 1,
                'unit_price' => 1000,
            ],
        ],
    ]);

    $validator = Validator::make(
        $request->all(),
        $request->rules()
    );

    $this->assertFalse(
        $validator->fails(),
        $validator->errors()->toJson()
    );
}
}