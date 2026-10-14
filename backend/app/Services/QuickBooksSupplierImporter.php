<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports QuickBooks Online Vendor records into Bizflow's tenant-scoped
 * suppliers table. QuickBooks is read-only; all writes are made to Bizflow.
 */
class QuickBooksSupplierImporter
{
    private const ENTITY_TYPE = 'suppliers';
    private const LOCAL_TYPE = Supplier::class;
    private const PAGE_SIZE = 1000;
    private const MAX_PAGES = 50;

    public function __construct(private QuickBooksAccountingApiService $quickBooks)
    {
    }

    public function import(QuickBooksConnection $connection, int $tenantId): array
    {
        if ((int) $connection->tenant_id !== $tenantId) {
            throw new RuntimeException('The QuickBooks connection does not belong to this tenant.');
        }

        if ($connection->connection_status !== 'connected') {
            throw new QuickBooksApiException('This QuickBooks connection is not active.', 409);
        }

        // Read from the provider before opening the local transaction.
        $records = $this->fetchAllSuppliers($connection);

        return DB::transaction(function () use ($connection, $tenantId, $records): array {
            $imported = 0;
            $alreadyImported = 0;
            $skipped = 0;

            foreach ($records as $record) {
                $quickBooksId = isset($record['Id']) ? trim((string) $record['Id']) : '';
                $name = $this->supplierName($record);

                // A source ID and supplier display name are required. Never use
                // a display name or email as a substitute for source identity.
                if ($quickBooksId === '' || $name === '') {
                    $skipped++;
                    continue;
                }

                $mapping = QuickBooksImportMapping::query()
                    ->where('tenant_id', $tenantId)
                    ->where('quickbooks_connection_id', $connection->id)
                    ->where('entity_type', self::ENTITY_TYPE)
                    ->where('quickbooks_id', $quickBooksId)
                    ->first();

                if ($mapping && $mapping->local_type !== self::LOCAL_TYPE) {
                    throw new RuntimeException('A source mapping has an unexpected destination type.');
                }

                $supplier = null;
                if ($mapping) {
                    $supplier = Supplier::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($mapping->local_id)
                        ->first();
                }

                if ($supplier) {
                    // Preserve Bizflow-side edits on repeat runs.
                    $alreadyImported++;
                } else {
                    $supplier = Supplier::query()->create([
                        'tenant_id' => $tenantId,
                        'name' => $name,
                        'company_name' => $this->nullableString($record['CompanyName'] ?? null),
                        'email' => $this->nullableString(data_get($record, 'PrimaryEmailAddr.Address')),
                        'phone' => $this->nullableString(data_get($record, 'PrimaryPhone.FreeFormNumber')),
                        'address' => $this->formatAddress($record['BillAddr'] ?? $record['VendorAddr'] ?? []),
                        'status' => $this->isActive($record['Active'] ?? true) ? 'active' : 'inactive',
                        'notes' => $this->nullableString($record['Notes'] ?? null),
                    ]);
                    $imported++;
                }

                $payloadHash = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
                $metadata = [
                    'display_name' => $name,
                    'company_name' => $this->nullableString($record['CompanyName'] ?? null),
                    'source_active' => $this->isActive($record['Active'] ?? true),
                    'vendor_1099' => $this->isActive($record['Vendor1099'] ?? false),
                ];

                if ($mapping) {
                    $mapping->forceFill([
                        'local_type' => self::LOCAL_TYPE,
                        'local_id' => $supplier->id,
                        'payload_hash' => $payloadHash,
                        'imported_at' => now(),
                        'metadata' => $metadata,
                    ])->save();
                } else {
                    QuickBooksImportMapping::query()->create([
                        'tenant_id' => $tenantId,
                        'quickbooks_connection_id' => $connection->id,
                        'entity_type' => self::ENTITY_TYPE,
                        'quickbooks_id' => $quickBooksId,
                        'local_type' => self::LOCAL_TYPE,
                        'local_id' => $supplier->id,
                        'payload_hash' => $payloadHash,
                        'imported_at' => now(),
                        'metadata' => $metadata,
                    ]);
                }
            }

            return [
                'connection_id' => $connection->id,
                'entity_type' => self::ENTITY_TYPE,
                'fetched_count' => count($records),
                'imported_count' => $imported,
                'already_imported_count' => $alreadyImported,
                'skipped_count' => $skipped,
            ];
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAllSuppliers(QuickBooksConnection $connection): array
    {
        $records = [];
        $startPosition = 1;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->quickBooks->query(
                $connection,
                'select * from Vendor startposition '.$startPosition.' maxresults '.self::PAGE_SIZE,
            );

            $rows = $response['Vendor'] ?? [];
            if (! is_array($rows)) {
                throw new QuickBooksApiException('QuickBooks returned an unexpected supplier list.', 502);
            }

            $rows = array_values(array_filter($rows, 'is_array'));
            $records = array_merge($records, $rows);

            if (count($rows) < self::PAGE_SIZE) {
                return $records;
            }

            $startPosition += count($rows);
        }

        throw new QuickBooksApiException(
            'The QuickBooks supplier list exceeds the current import safety limit. Please contact support.',
            413,
        );
    }

    /** Choose a stable vendor name; do not use email or source IDs as names. */
    private function supplierName(array $record): string
    {
        foreach (['DisplayName', 'FullyQualifiedName', 'CompanyName', 'PrintOnCheckName', 'Name'] as $field) {
            $candidate = $this->nullableString($record[$field] ?? null);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return '';
    }

    private function formatAddress(mixed $address): ?string
    {
        if (! is_array($address)) {
            return null;
        }

        $parts = [];
        foreach ([
            'Line1', 'Line2', 'Line3', 'City',
            'CountrySubDivisionCode', 'PostalCode', 'Country',
        ] as $field) {
            $value = $this->nullableString($address[$field] ?? null);
            if ($value !== null) {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function isActive(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? true;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
