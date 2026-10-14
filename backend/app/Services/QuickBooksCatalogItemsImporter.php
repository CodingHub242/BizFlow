<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports sellable QuickBooks Online Item records into Bizflow's existing
 * tenant-scoped catalog_items table. All writes are local to Bizflow.
 *
 * Inventory quantities are intentionally not posted to branch inventory here:
 * a separate opening-stock workflow must establish the correct branch and
 * inventory movements. QuickBooks Taxable flags are retained in mapping
 * metadata; they are not tax rates and must not be converted to percentages.
 */
class QuickBooksCatalogItemsImporter
{
    private const ENTITY_TYPE = 'catalog_items';
    private const LOCAL_TYPE = CatalogItem::class;
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

        // Fetch all provider pages before opening a local database transaction.
        $records = $this->fetchAllItems($connection);

        return DB::transaction(function () use ($connection, $tenantId, $records): array {
            $imported = 0;
            $alreadyImported = 0;
            $skipped = 0;

            foreach ($records as $record) {
                $quickBooksId = $this->nullableString($record['Id'] ?? null);
                $name = $this->itemName($record);
                $sourceType = strtolower((string) ($this->nullableString($record['Type'] ?? null) ?? ''));

                if ($quickBooksId === null || $name === '') {
                    $skipped++;
                    continue;
                }

                $destination = $this->destinationType($sourceType);
                // Category/group rows and future/unrecognized types do not
                // represent a single simple Bizflow product or service.
                if ($destination === null) {
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

                $catalogItem = null;
                if ($mapping) {
                    $catalogItem = CatalogItem::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($mapping->local_id)
                        ->first();
                }

                if ($catalogItem) {
                    // Imported items are never overwritten by repeat imports.
                    $alreadyImported++;
                } else {
                    $sku = $this->nullableString($record['Sku'] ?? null);

                    // Bizflow enforces SKU uniqueness per tenant. Never silently
                    // change a source SKU or collide with a locally-created SKU.
                    if ($sku !== null && CatalogItem::query()
                        ->where('tenant_id', $tenantId)
                        ->where('sku', $sku)
                        ->exists()) {
                        $skipped++;
                        continue;
                    }

                    $catalogItem = CatalogItem::query()->create([
                        'tenant_id' => $tenantId,
                        'name' => $name,
                        'type' => $destination['type'],
                        'sku' => $sku,
                        'description' => $this->description($record),
                        'unit' => $this->unit($record),
                        'cost_price' => $this->amount($record['PurchaseCost'] ?? null),
                        'selling_price' => $this->amount($record['UnitPrice'] ?? null),
                        // QuickBooks exposes a taxable flag, not a percentage.
                        // Keep the Bizflow rate neutral rather than inventing one.
                        'tax_rate' => 0,
                        'track_inventory' => $destination['track_inventory'],
                        'is_active' => $this->isActive($record['Active'] ?? true),
                    ]);
                    $imported++;
                }

                $metadata = [
                    'source_name' => $name,
                    'source_type' => $sourceType,
                    'source_active' => $this->isActive($record['Active'] ?? true),
                    'source_taxable' => $this->nullableBoolean($record['Taxable'] ?? null),
                    'source_qty_on_hand' => $this->numericOrNull($record['QtyOnHand'] ?? null),
                    'source_parent_id' => $this->nullableString(data_get($record, 'ParentRef.value')),
                    'source_income_account_id' => $this->nullableString(data_get($record, 'IncomeAccountRef.value')),
                    'source_expense_account_id' => $this->nullableString(data_get($record, 'ExpenseAccountRef.value')),
                    'source_asset_account_id' => $this->nullableString(data_get($record, 'AssetAccountRef.value')),
                ];
                $payloadHash = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));

                if ($mapping) {
                    $mapping->forceFill([
                        'local_type' => self::LOCAL_TYPE,
                        'local_id' => $catalogItem->id,
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
                        'local_id' => $catalogItem->id,
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
    private function fetchAllItems(QuickBooksConnection $connection): array
    {
        $records = [];
        $startPosition = 1;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->quickBooks->query(
                $connection,
                'select * from Item startposition '.$startPosition.' maxresults '.self::PAGE_SIZE,
            );

            $rows = $response['Item'] ?? [];
            if (! is_array($rows)) {
                throw new QuickBooksApiException('QuickBooks returned an unexpected catalog item list.', 502);
            }

            $rows = array_values(array_filter($rows, 'is_array'));
            $records = array_merge($records, $rows);

            if (count($rows) < self::PAGE_SIZE) {
                return $records;
            }

            $startPosition += count($rows);
        }

        throw new QuickBooksApiException(
            'The QuickBooks item list exceeds the current import safety limit. Please contact support.',
            413,
        );
    }

    /** @return array{type: string, track_inventory: bool}|null */
    private function destinationType(string $sourceType): ?array
    {
        return match ($sourceType) {
            'inventory' => ['type' => 'product', 'track_inventory' => true],
            'noninventory' => ['type' => 'product', 'track_inventory' => false],
            'service' => ['type' => 'service', 'track_inventory' => false],
            default => null,
        };
    }

    private function itemName(array $record): string
    {
        foreach (['Name', 'FullyQualifiedName'] as $field) {
            $candidate = $this->nullableString($record[$field] ?? null);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return '';
    }

    private function description(array $record): ?string
    {
        foreach (['SalesDesc', 'Description', 'PurchaseDesc'] as $field) {
            $candidate = $this->nullableString($record[$field] ?? null);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function unit(array $record): ?string
    {
        foreach ([data_get($record, 'UnitOfMeasureSetRef.name'), $record['Unit'] ?? null] as $value) {
            $candidate = $this->nullableString($value);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function amount(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $amount = (float) $value;
        return $amount >= 0 ? round($amount, 2) : 0.0;
    }

    private function numericOrNull(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        return floor($number) === $number ? (int) $number : $number;
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
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
