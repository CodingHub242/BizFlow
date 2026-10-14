<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\ChartOfAccount;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports QuickBooks Online Bill records into Bizflow's tenant-scoped bills
 * tables. It never mutates QuickBooks and never receives stock into inventory.
 */
class QuickBooksBillsImporter
{
    private const ENTITY_TYPE = 'bills';
    private const LOCAL_TYPE = Bill::class;
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

        // Fetch the complete provider result before opening a local transaction.
        $records = $this->fetchAllBills($connection);

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

                $bill = null;
                if ($mapping) {
                    $bill = Bill::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($mapping->local_id)
                        ->first();
                }

                if ($bill) {
                    // Don't overwrite local edits or append duplicate lines on re-import.
                    $alreadyImported++;
                    continue;
                }

                $vendorSourceId = $this->nullableString(data_get($record, 'VendorRef.value'));
                if ($vendorSourceId === null) {
                    $skipped++;
                    continue;
                }

                $supplierMapping = $this->sourceMapping(
                    $tenantId,
                    (int) $connection->id,
                    'suppliers',
                    $vendorSourceId,
                );

                if (! $supplierMapping || $supplierMapping->local_type !== Supplier::class) {
                    // Importing without a matched supplier would break the bill relationship.
                    $skipped++;
                    continue;
                }

                $supplier = Supplier::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($supplierMapping->local_id)
                    ->first();

                if (! $supplier) {
                    $skipped++;
                    continue;
                }

                $lines = $this->normaliseLines($record['Line'] ?? []);
                if ($lines === null) {
                    $skipped++;
                    continue;
                }

                $total = $this->amount($record['TotalAmt'] ?? 0);
                $balance = $this->amount($record['Balance'] ?? $total);
                $paymentStatus = $balance <= 0
                    ? 'paid'
                    : ($balance < $total ? 'partially_paid' : 'unpaid');

                $bill = Bill::query()->create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branch->id,
                    'supplier_id' => $supplier->id,
                    'created_by' => $userId,
                    'bill_number' => $this->nullableString($record['DocNumber'] ?? null),
                    'bill_date' => $date,
                    'due_date' => $this->validDate($record['DueDate'] ?? null),
                    'total' => $total,
                    'balance' => $balance,
                    'currency' => $this->currencyCode(data_get($record, 'CurrencyRef.value')),
                    'status' => $balance <= 0 ? 'closed' : 'open',
                    'payment_status' => $paymentStatus,
                    'source_status' => $this->nullableString($record['TxnStatus'] ?? null),
                    'notes' => $this->nullableString($record['PrivateNote'] ?? null),
                ]);

                foreach ($lines as $index => $line) {
                    $detailType = $this->nullableString($line['DetailType'] ?? null);
                    $itemSourceId = $this->nullableString(data_get($line, 'ItemBasedExpenseLineDetail.ItemRef.value'));
                    $accountSourceId = $this->nullableString(data_get($line, 'AccountBasedExpenseLineDetail.AccountRef.value'));
                    $catalogItemId = null;
                    $chartOfAccountId = null;

                    if ($itemSourceId !== null) {
                        $itemMapping = $this->sourceMapping(
                            $tenantId,
                            (int) $connection->id,
                            'catalog_items',
                            $itemSourceId,
                        );
                        if ($itemMapping && $itemMapping->local_type === CatalogItem::class
                            && CatalogItem::query()->where('tenant_id', $tenantId)->whereKey($itemMapping->local_id)->exists()) {
                            $catalogItemId = $itemMapping->local_id;
                        } else {
                            $unresolvedLineReferences++;
                        }
                    } elseif ($accountSourceId !== null) {
                        $accountMapping = $this->sourceMapping(
                            $tenantId,
                            (int) $connection->id,
                            'chart_of_accounts',
                            $accountSourceId,
                        );
                        if ($accountMapping && $accountMapping->local_type === ChartOfAccount::class
                            && ChartOfAccount::query()->where('tenant_id', $tenantId)->whereKey($accountMapping->local_id)->exists()) {
                            $chartOfAccountId = $accountMapping->local_id;
                        } else {
                            $unresolvedLineReferences++;
                        }
                    }

                    $quantity = $this->numericOrNull(
                        data_get($line, 'ItemBasedExpenseLineDetail.Qty')
                        ?? data_get($line, 'AccountBasedExpenseLineDetail.Qty'),
                    );
                    $unitPrice = $this->numericOrNull(
                        data_get($line, 'ItemBasedExpenseLineDetail.UnitPrice')
                        ?? data_get($line, 'AccountBasedExpenseLineDetail.UnitPrice'),
                    );

                    BillItem::query()->create([
                        'tenant_id' => $tenantId,
                        'bill_id' => $bill->id,
                        'catalog_item_id' => $catalogItemId,
                        'chart_of_account_id' => $chartOfAccountId,
                        'line_type' => $detailType,
                        'source_line_id' => $this->nullableString($line['Id'] ?? null),
                        'description' => $this->nullableString($line['Description'] ?? null),
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'amount' => $this->amount($line['Amount'] ?? 0),
                        'line_order' => $index + 1,
                        'source_data' => $line,
                    ]);
                }

                $payloadHash = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
                $metadata = [
                    'source_doc_number' => $this->nullableString($record['DocNumber'] ?? null),
                    'source_vendor_id' => $vendorSourceId,
                    'source_vendor_name' => $this->nullableString(data_get($record, 'VendorRef.name')),
                    'source_line_count' => count($lines),
                    'branch_id' => $branch->id,
                ];

                $mappingData = [
                    'tenant_id' => $tenantId,
                    'quickbooks_connection_id' => $connection->id,
                    'entity_type' => self::ENTITY_TYPE,
                    'quickbooks_id' => $quickBooksId,
                    'local_type' => self::LOCAL_TYPE,
                    'local_id' => $bill->id,
                    'payload_hash' => $payloadHash,
                    'imported_at' => now(),
                    'metadata' => $metadata,
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
    private function fetchAllBills(QuickBooksConnection $connection): array
    {
        $records = [];
        $startPosition = 1;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->quickBooks->query(
                $connection,
                'select * from Bill startposition '.$startPosition.' maxresults '.self::PAGE_SIZE,
            );

            $rows = $response['Bill'] ?? [];
            if (! is_array($rows)) {
                throw new QuickBooksApiException('QuickBooks returned an unexpected bill list.', 502);
            }

            $rows = array_values(array_filter($rows, 'is_array'));
            $records = array_merge($records, $rows);
            if (count($rows) < self::PAGE_SIZE) {
                return $records;
            }
            $startPosition += count($rows);
        }

        throw new QuickBooksApiException(
            'The QuickBooks bill list exceeds the current import safety limit. Please contact support.',
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
        // Defensive support for a provider response containing a single Line object.
        return isset($lines['DetailType']) || isset($lines['Amount']) ? [$lines] : null;
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
