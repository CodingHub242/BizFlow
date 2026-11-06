<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EngageContact;
use App\Models\Tenant;
use App\Services\Engage\EngageContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EngageContactServiceTest extends TestCase
{
    use RefreshDatabase;

    protected EngageContactService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(EngageContactService::class);
    }

    public function test_inbound_whatsapp_message_creates_engage_prospect(): void
    {
        $tenant = Tenant::factory()->create();

        $contact = $this->service->resolveInboundContact(
            $tenant->id,
            '0241234567',
            'Kwame Mensah'
        );

        $this->assertDatabaseHas('engage_contacts', [
            'id' => $contact->id,
            'tenant_id' => $tenant->id,
            'name' => 'Kwame Mensah',
            'phone' => '0241234567',
            'normalized_phone' => '+233241234567',
            'source' => 'whatsapp',
            'lifecycle_status' => 'prospect',
            'status' => 'active',
        ]);

        $this->assertNull($contact->customer_id);

        // An incoming message must not create a Core customer.
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_inbound_contact_links_to_existing_core_customer(): void
    {
        $tenant = Tenant::factory()->create();

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Kwame Mensah',
            'phone' => '0241234567',
        ]);

        $contact = $this->service->resolveInboundContact(
            $tenant->id,
            '0241234567',
            'Kwame Mensah'
        );

        $this->assertSame($customer->id, $contact->customer_id);
        $this->assertSame('customer', $contact->lifecycle_status);

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_local_and_international_phone_formats_resolve_same_contact(): void
    {
        $tenant = Tenant::factory()->create();

        $firstContact = $this->service->resolveInboundContact(
            $tenant->id,
            '0241234567',
            'Kwame Mensah'
        );

        $secondContact = $this->service->resolveInboundContact(
            $tenant->id,
            '+233241234567',
            'Kwame Mensah'
        );

        $this->assertSame($firstContact->id, $secondContact->id);

        $this->assertDatabaseCount('engage_contacts', 1);
    }

    public function test_existing_contact_is_updated_instead_of_duplicated(): void
    {
        $tenant = Tenant::factory()->create();

        $contact = $this->service->resolveInboundContact(
            $tenant->id,
            '0241234567'
        );

        $updatedContact = $this->service->resolveInboundContact(
            $tenant->id,
            '0241234567',
            'Kwame Mensah'
        );

        $this->assertSame($contact->id, $updatedContact->id);
        $this->assertSame('Kwame Mensah', $updatedContact->name);

        $this->assertDatabaseCount('engage_contacts', 1);
    }

    public function test_same_phone_number_can_exist_for_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $contactA = $this->service->resolveInboundContact(
            $tenantA->id,
            '0241234567'
        );

        $contactB = $this->service->resolveInboundContact(
            $tenantB->id,
            '0241234567'
        );

        $this->assertNotSame($contactA->id, $contactB->id);

        $this->assertSame($tenantA->id, $contactA->tenant_id);
        $this->assertSame($tenantB->id, $contactB->tenant_id);

        $this->assertDatabaseCount('engage_contacts', 2);
    }

    public function test_soft_deleted_contact_is_restored_instead_of_duplicated(): void
    {
        $tenant = Tenant::factory()->create();

        $contact = $this->service->resolveInboundContact(
            $tenant->id,
            '0241234567'
        );

        $contact->delete();

        $restoredContact = $this->service->resolveInboundContact(
            $tenant->id,
            '0241234567',
            'Kwame Mensah'
        );

        $this->assertSame($contact->id, $restoredContact->id);
        $this->assertNull($restoredContact->deleted_at);

        $this->assertDatabaseCount('engage_contacts', 1);

        $this->assertDatabaseHas('engage_contacts', [
            'id' => $contact->id,
            'deleted_at' => null,
        ]);
    }

    public function test_invalid_ghanaian_phone_number_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->service->resolveInboundContact(
            $tenant->id,
            '12345'
        );
    }

    public function test_phone_normalization_supports_local_and_international_formats(): void
    {
        $this->assertSame(
            '+233241234567',
            $this->service->normalizeGhanaPhone('0241234567')
        );

        $this->assertSame(
            '+233241234567',
            $this->service->normalizeGhanaPhone('+233241234567')
        );

        $this->assertSame(
            '+233501234567',
            $this->service->normalizeGhanaPhone('0501234567')
        );

        $this->assertSame(
            '+233501234567',
            $this->service->normalizeGhanaPhone('233501234567')
        );

        $this->assertNull(
            $this->service->normalizeGhanaPhone('12345')
        );
    }
}
