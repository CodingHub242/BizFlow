<?php

namespace App\Services\Engage;

use App\Models\Customer;
use App\Models\EngageContact;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EngageContactService
{
    /**
     * Resolve or create an Engage contact from an incoming
     * WhatsApp message.
     *
     * This method never creates a Core customer.
     */
    public function resolveInboundContact(
        int $tenantId,
        string $phone,
        ?string $name = null
    ): EngageContact {
        $normalizedPhone = $this->normalizeGhanaPhone($phone);

        if (!$normalizedPhone) {
            throw new InvalidArgumentException(
                'A valid Ghanaian phone number is required.'
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $phone,
            $normalizedPhone,
            $name
        ) {
            $now = now();

            // Find an existing Engage contact, including soft-deleted ones.
            $contact = EngageContact::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('normalized_phone', $normalizedPhone)
                ->lockForUpdate()
                ->first();

            // Find a matching Core customer within this tenant.
            $customer = $this->findCoreCustomer(
                $tenantId,
                $normalizedPhone
            );

            if ($contact) {
                if ($contact->trashed()) {
                    $contact->restore();
                }

                $contact->phone = $phone;

                if ($name && !$contact->name) {
                    $contact->name = $name;
                }

                if (!$contact->customer_id && $customer) {
                    $contact->customer_id = $customer->id;
                    $contact->lifecycle_status = 'customer';
                }

                $contact->last_contacted_at = $now;

                if (!$contact->first_contacted_at) {
                    $contact->first_contacted_at = $now;
                }

                $contact->save();

                return $contact->fresh();
            }

            return EngageContact::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer?->id,
                'name' => $name,
                'phone' => $phone,
                'normalized_phone' => $normalizedPhone,
                'source' => 'whatsapp',
                'lifecycle_status' => $customer
                    ? 'customer'
                    : 'prospect',
                'status' => 'active',
                'first_contacted_at' => $now,
                'last_contacted_at' => $now,
            ]);
        });
    }

    /**
     * Normalize supported Ghanaian phone formats to +233XXXXXXXXX.
     */
    public function normalizeGhanaPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (!$digits) {
            return null;
        }

       // Local Ghana format: 0241234567 or 0501234567
        if (preg_match('/^0[25]\d{8}$/', $digits)) {
            return '+233' . substr($digits, 1);
        }

        // International format: 233241234567 or 233501234567
        if (preg_match('/^233[25]\d{8}$/', $digits)) {
            return '+' . $digits;
        }

        return null;
    }

    /**
     * Find an existing Core customer using tenant-scoped phone matching.
     */
    protected function findCoreCustomer(
        int $tenantId,
        string $normalizedPhone
    ): ?Customer {
        $digits = ltrim($normalizedPhone, '+');
        $localNumber = '0' . substr($digits, 3);

        return Customer::query()
            ->where('tenant_id', $tenantId)
            ->whereIn(
                DB::raw("regexp_replace(phone, '[^0-9]', '', 'g')"),
                [$digits, $localNumber]
            )
            ->first();
    }
}