<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports QuickBooks Online Estimate records into Bizflow's estimates tables.
 * It is read-only against QuickBooks and never creates invoices or inventory movements.
 */
class QuickBooksEstimatesImporter
{
    private const ENTITY_TYPE = 'estimates';
    private const LOCAL_TYPE = Estimate::class;
    private const PAGE_SIZE = 1000;
    private const MAX_PAGES = 50;

    public function __construct(private QuickBooksAccountingApiService $quickBooks)
    {
    }

    public function import(
        QuickBooksConnection $connection,
        int $tenantId,
        int $branchId,
        int $userId,
    ): array {
        if ((int) $connection->tenant_id !== $tenantId) {
            throw new RuntimeException('The QuickBooks connection does not belong to this tenant.');
        }

        if ($connection->connection_status !== 'connected') {
            throw new QuickBooksApiException('This QuickBooks connection is not active.', 409);
        }

        $branch = Branch::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($branchId)
            ->first();

        if (! $branch) {
            throw new QuickBooksApiException('Select a branch that belongs to this Bizflow tenant.', 422);
        }

        // Fetch provider data before beginning the local DB transaction.
        $records = $this->fetchAllEstimates($connection);

        return DB::transaction(function () use ($connection, $tenantId, $branch, $userId, $records): array {
            $imported = 0;
            $alreadyImported = 0;
            $skipped = 0;
            $unresolvedLineReferences = 0;

            foreach ($records as $record) {
                $quickBooksId = $this->nullableString($record['Id'] ?? null);
                $date = $this->validDate($record['TxnDate'] ?? null);

                if ($quickBooksId === null || $date === null) {
                    $skipped++;
                    continue;
                }

                $mapping = $this->sourceMapping(
                    $tenantId,
                    (int) $connection->id,
                    self::ENTITY_TYPE,
                    $quickBooksId,
                );

                if ($mapping && $mapping->local_type !== self::LOCAL_TYPE) {
                    throw new RuntimeException('A source mapping has an unexpected destination type.');
                }

                if ($mapping) {
                    $existing = Estimate::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($mapping->local_id)
                        ->first();
                    if ($existing) {
                        // Deliberately don't overwrite local edits or append duplicate lines.
                        $alreadyImported++;
                        continue;
                    }
                }

                $customerSourceId = $this->nullableString(data_get($record, 'CustomerRef.value'));
                if ($customerSourceId === null) {
                    $skipped++;
                    continue;
                }

                $customerMapping = $this->sourceMapping(
                    $tenantId,
                    (int) $connection->id,
                    'customers',
                    $customerSourceId,
                );

                if (! $customerMapping || $customerMapping->local_type !== Customer::class) {
                    // Estimates need a real, tenant-owned customer relationship.
                    $skipped++;
                    continue;
                }

                $customer = Customer::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($customerMapping->local_id)
                    ->first();

                if (! $customer) {
                    $skipped++;
                    continue;
                }

                $lines = $this->normaliseLines($record['Line'] ?? []);
                if ($lines === null) {
                    $skipped++;
                    continue;
                }

                $sourceStatus = $this->nullableString($record['TxnStatus'] ?? null);
                $estimate = Estimate::query()->create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branch->id,
                    'customer_id' => $customer->id,
                    'created_by' => $userId,
                    'estimate_number' => $this->nullableString($record['DocNumber'] ?? null),
                    'estimate_date' => $date,
                    'expiry_date' => $this->validDate($record['ExpirationDate'] ?? null),
                    'total' => $this->amount($record['TotalAmt'] ?? 0),
                    'status' => $this->localStatus($sourceStatus),
                    'source_status' => $sourceStatus,
                    'currency' => $this->currencyCode(data_get($record, 'CurrencyRef.value')),
                    'notes' => $this->nullableString($record['PrivateNote'] ?? null),
                ]);

                foreach ($lines as $index => $line) {
                    $itemSourceId = $this->nullableString(data_get($line, 'SalesItemLineDetail.ItemRef.value'));
                    $itemSourceName = $this->nullableString(data_get($line, 'SalesItemLineDetail.ItemRef.name'));
                    $catalogItemId = null;
                    $catalogItemName = null;

                    if ($itemSourceId !== null) {
                        $itemMapping = $this->sourceMapping(
                            $tenantId,
                            (int) $connection->id,
                            'catalog_items',
                            $itemSourceId,
                        );

                        if ($itemMapping && $itemMapping->local_type === CatalogItem::class) {
                            $catalogItem = CatalogItem::query()
                                ->where('tenant_id', $tenantId)
                                ->whereKey($itemMapping->local_id)
                                ->first();
                            if ($catalogItem) {
                                $catalogItemId = $catalogItem->id;
                                $catalogItemName = $catalogItem->name;
                            } else {
                                $unresolvedLineReferences++;
                            }
                        } else {
                            $unresolvedLineReferences++;
                        }
                    }

                    $detailType = $this->nullableString($line['DetailType'] ?? null);
                    $quantity = $this->numericOrNull(data_get($line, 'SalesItemLineDetail.Qty')) ?? 1.0;
                    $unitPrice = $this->numericOrNull(data_get($line, 'SalesItemLineDetail.UnitPrice'));
                    $itemName = $catalogItemName ?? $itemSourceName;
                    if ($itemName === null && $detailType === 'DiscountLineDetail') {
                        $itemName = 'Discount';
                    }

                    EstimateItem::query()->create([
                        'tenant_id' => $tenantId,
                        'estimate_id' => $estimate->id,
                        'catalog_item_id' => $catalogItemId,
                        'source_line_id' => $this->nullableString($line['Id'] ?? null),
                        'line_type' => $detailType,
                        'item_name' => $itemName,
                        'description' => $this->nullableString($line['Description'] ?? null),
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice ?? $this->amount($line['Amount'] ?? 0),
                        'discount' => $this->amount(
                            $line['DiscountAmt']
                                ?? data_get($line, 'SalesItemLineDetail.DiscountAmt')
                                ?? data_get($line, 'DiscountLineDetail.DiscountAmt')
                                ?? 0,
                        ),
                        // Only map an explicitly numeric tax amount. TaxCodeRef is a code, not a rate.
                        'tax' => $this->amount(
                            $line['TaxAmount']
                                ?? data_get($line, 'SalesItemLineDetail.TaxAmount')
                                ?? 0,
                        ),
                        'line_total' => $this->amount($line['Amount'] ?? 0),
                        'line_order' => $index + 1,
                        'source_data' => $line,
                    ]);
                }

                $payloadHash = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
                $mappingData = [
                    'tenant_id' => $tenantId,
                    'quickbooks_connection_id' => $connection->id,
                    'entity_type' => self::ENTITY_TYPE,
                    'quickbooks_id' => $quickBooksId,
                    'local_type' => self::LOCAL_TYPE,
                    'local_id' => $estimate->id,
                    'payload_hash' => $payloadHash,
                    'imported_at' => now(),
                    'metadata' => [
                        'source_doc_number' => $this->nullableString($record['DocNumber'] ?? null),
                        'source_customer_id' => $customerSourceId,
                        'source_customer_name' => $this->nullableString(data_get($record, 'CustomerRef.name')),
                        'source_line_count' => count($lines),
                        'source_tax_detail' => $record['TxnTaxDetail'] ?? null,
                        'branch_id' => $branch->id,
                    ],
                ];

                if ($mapping) {
                    $mapping->forceFill($mappingData)->save();
                } else {
                    QuickBooksImportMapping::query()->create($mappingData);
                }
                $imported++;
            }

            return [
                'connection_id' => $connection->id,
                'entity_type' => self::ENTITY_TYPE,
                'branch_id' => $branch->id,
                'fetched_count' => count($records),
                'imported_count' => $imported,
                'already_imported_count' => $alreadyImported,
                'skipped_count' => $skipped,
                'unresolved_line_reference_count' => $unresolvedLineReferences,
            ];
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAllEstimates(QuickBooksConnection $connection): array
    {
        $records = [];
        $startPosition = 1;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->quickBooks->query(
                $connection,
                'select * from Estimate startposition '.$startPosition.' maxresults '.self::PAGE_SIZE,
            );

            $rows = $response['Estimate'] ?? [];
            if (! is_array($rows)) {
                throw new QuickBooksApiException('QuickBooks returned an unexpected estimate list.', 502);
            }

            $rows = array_values(array_filter($rows, 'is_array'));
            $records = array_merge($records, $rows);
            if (count($rows) < self::PAGE_SIZE) {
                return $records;
            }
            $startPosition += count($rows);
        }

        throw new QuickBooksApiException(
            'The QuickBooks estimate list exceeds the current import safety limit. Please contact support.',
            413,
        );
    }

    private function sourceMapping(int $tenantId, int $connectionId, string $entityType, string $sourceId): ?QuickBooksImportMapping
    {
        return QuickBooksImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('quickbooks_connection_id', $connectionId)
            ->where('entity_type', $entityType)
            ->where('quickbooks_id', $sourceId)
            ->first();
    }

    /** @return array<int, array<string, mixed>>|null */
    private function normaliseLines(mixed $lines): ?array
    {
        if (! is_array($lines)) {
            return null;
        }
        if ($lines === []) {
            return [];
        }
        if (array_is_list($lines)) {
            return array_values(array_filter($lines, 'is_array'));
        }
        return isset($lines['DetailType']) || isset($lines['Amount']) ? [$lines] : null;
    }

    private function localStatus(?string $sourceStatus): string
    {
        return match (strtolower(trim((string) $sourceStatus))) {
            'accepted' => 'accepted',
            'closed' => 'closed',
            'rejected' => 'rejected',
            default => 'draft',
        };
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match)) {
            return null;
        }
        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]) ? $value : null;
    }

    private function currencyCode(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }
        return strlen($value) <= 3 ? strtoupper($value) : null;
    }

    private function amount(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    private function numericOrNull(mixed $value): int|float|null
    {
        return is_numeric($value) ? (float) $value : null;
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
