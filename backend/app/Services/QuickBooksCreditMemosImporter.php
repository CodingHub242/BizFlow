<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\CreditMemo;
use App\Models\CreditMemoItem;
use App\Models\Customer;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Imports QuickBooks Online CreditMemo records into Bizflow's credit memo tables.
 * It preserves source links but does not create cash payments or automatically
 * apply credits to invoices. Credit application is a separate reconciliation step.
 */
class QuickBooksCreditMemosImporter
{
    private const ENTITY_TYPE = 'credit_memos';
    private const LOCAL_TYPE = CreditMemo::class;
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

        if (! Schema::hasTable('credit_memos') || ! Schema::hasTable('credit_memo_items')) {
            throw new QuickBooksApiException('Bizflow credit memo tables are not available.', 409);
        }

        // Complete all provider reads before opening the local database transaction.
        $records = $this->fetchAllCreditMemos($connection);

        return DB::transaction(function () use ($connection, $tenantId, $branch, $userId, $records): array {
            $imported = 0;
            $alreadyImported = 0;
            $skipped = 0;
            $skippedDiagnostics = [];

            foreach ($records as $record) {
                $quickBooksId = $this->nullableString($record['Id'] ?? null);
                $date = $this->validDate($record['TxnDate'] ?? null);
                $total = $this->numericOrNull($record['TotalAmt'] ?? null);

                if ($quickBooksId === null || $date === null || $total === null || $total < 0) {
                    $skipped++;
                    $skippedDiagnostics[] = [
                        'quickbooks_credit_memo_id' => $quickBooksId,
                        'reason' => 'missing_or_invalid_required_fields',
                    ];
                    continue;
                }

                $mapping = $this->sourceMapping(
                    $tenantId,
                    (int) $connection->id,
                    self::ENTITY_TYPE,
                    $quickBooksId,
                );

                if ($mapping && $mapping->local_type !== self::LOCAL_TYPE) {
                    throw new RuntimeException('A credit memo source mapping has an unexpected destination type.');
                }

                if ($mapping) {
                    $localExists = CreditMemo::withTrashed()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($mapping->local_id)
                        ->exists();

                    if ($localExists) {
                        // Do not overwrite local edits (notes/status/etc.) on re-import.
                        $alreadyImported++;
                        continue;
                    }
                }

                $customerSourceId = $this->nullableString(data_get($record, 'CustomerRef.value'));
                if ($customerSourceId === null) {
                    $skipped++;
                    $skippedDiagnostics[] = [
                        'quickbooks_credit_memo_id' => $quickBooksId,
                        'reason' => 'missing_customer_ref',
                    ];
                    continue;
                }

                $customerMapping = $this->sourceMapping(
                    $tenantId,
                    (int) $connection->id,
                    'customers',
                    $customerSourceId,
                );

                if (! $customerMapping || $customerMapping->local_type !== Customer::class) {
                    $skipped++;
                    $skippedDiagnostics[] = [
                        'quickbooks_credit_memo_id' => $quickBooksId,
                        'source_customer_id' => $customerSourceId,
                        'reason' => $customerMapping ? 'customer_mapping_destination_type_mismatch' : 'customer_mapping_not_found',
                    ];
                    continue;
                }

                $customer = Customer::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($customerMapping->local_id)
                    ->first();

                if (! $customer) {
                    $skipped++;
                    $skippedDiagnostics[] = [
                        'quickbooks_credit_memo_id' => $quickBooksId,
                        'source_customer_id' => $customerSourceId,
                        'reason' => 'mapped_local_customer_missing',
                    ];
                    continue;
                }

                $lines = $this->normaliseLines($record['Line'] ?? []);
                if ($lines === null) {
                    $skipped++;
                    $skippedDiagnostics[] = [
                        'quickbooks_credit_memo_id' => $quickBooksId,
                        'reason' => 'invalid_line_structure',
                    ];
                    continue;
                }

                $tax = round((float) ($this->numericOrNull(data_get($record, 'TxnTaxDetail.TotalTax')) ?? 0), 2);
                $discount = round((float) ($this->numericOrNull($record['DiscountAmt'] ?? null) ?? 0), 2);
                $lineTotal = round(array_sum(array_map(
                    fn (array $line): float => (float) ($this->numericOrNull($line['Amount'] ?? null) ?? 0),
                    $lines,
                )), 2);
                // Prefer the source total. Derive subtotal only when the source doesn't expose it.
                $subtotal = $this->numericOrNull($record['SubTotal'] ?? null);
                $subtotal = round($subtotal ?? max(0, $total - $tax + $discount), 2);

                $creditMemo = CreditMemo::query()->create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branch->id,
                    'customer_id' => $customer->id,
                    'created_by' => $userId,
                    'credit_memo_number' => $this->nullableString($record['DocNumber'] ?? null),
                    'status' => 'open',
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'tax' => $tax,
                    'total' => round($total, 2),
                    'currency_code' => $this->nullableString(data_get($record, 'CurrencyRef.value')),
                    'issued_at' => $date,
                    'notes' => $this->nullableString($record['PrivateNote'] ?? $record['CustomerMemo']['value'] ?? null),
                ]);

                foreach ($lines as $index => $line) {
                    $detailType = $this->nullableString($line['DetailType'] ?? null);
                    $salesDetail = is_array($line['SalesItemLineDetail'] ?? null)
                        ? $line['SalesItemLineDetail']
                        : [];
                    $sourceCatalogId = $this->nullableString(data_get($salesDetail, 'ItemRef.value'));
                    $catalogItemId = null;

                    if ($sourceCatalogId !== null) {
                        $catalogMapping = $this->sourceMapping(
                            $tenantId,
                            (int) $connection->id,
                            'catalog_items',
                            $sourceCatalogId,
                        );

                        if ($catalogMapping && $catalogMapping->local_type === CatalogItem::class) {
                            $catalogExists = CatalogItem::query()
                                ->where('tenant_id', $tenantId)
                                ->whereKey($catalogMapping->local_id)
                                ->exists();
                            if ($catalogExists) {
                                $catalogItemId = (int) $catalogMapping->local_id;
                            }
                        }
                    }

                    $quantity = $this->numericOrNull($salesDetail['Qty'] ?? null);
                    $unitPrice = $this->numericOrNull($salesDetail['UnitPrice'] ?? null);
                    $amount = round((float) ($this->numericOrNull($line['Amount'] ?? null) ?? 0), 2);
                    $lineDiscount = round((float) ($this->numericOrNull(data_get($salesDetail, 'DiscountAmt')) ?? 0), 2);
                    $lineTax = round((float) ($this->numericOrNull(data_get($salesDetail, 'TaxAmount')) ?? 0), 2);

                    CreditMemoItem::query()->create([
                        'tenant_id' => $tenantId,
                        'credit_memo_id' => $creditMemo->id,
                        'catalog_item_id' => $catalogItemId,
                        'line_number' => $index + 1,
                        'source_line_id' => $this->nullableString($line['Id'] ?? null),
                        'description' => $this->nullableString($line['Description'] ?? data_get($salesDetail, 'ItemRef.name')),
                        'quantity' => $quantity ?? 1,
                        'unit_price' => $unitPrice ?? $amount,
                        'discount' => $lineDiscount,
                        'tax' => $lineTax,
                        'line_total' => $amount,
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
                    'local_id' => $creditMemo->id,
                    'payload_hash' => $payloadHash,
                    'imported_at' => now(),
                    'metadata' => [
                        'source_doc_number' => $this->nullableString($record['DocNumber'] ?? null),
                        'source_customer_id' => $customerSourceId,
                        'source_customer_name' => $this->nullableString(data_get($record, 'CustomerRef.name')),
                        'source_total_amount' => round($total, 2),
                        'source_line_count' => count($lines),
                        'source_lines' => $lines,
                        'source_linked_transactions' => $this->linkedTransactions($lines),
                        'branch_id' => $branch->id,
                        'credit_applications_deferred' => true,
                    ],
                ];

                if ($mapping) {
                    $mapping->forceFill($mappingData)->save();
                } else {
                    QuickBooksImportMapping::query()->create($mappingData);
                }

                $imported++;
            }

            if ($skippedDiagnostics !== []) {
                Log::warning('QuickBooks credit memos import skipped records.', [
                    'connection_id' => $connection->id,
                    'tenant_id' => $tenantId,
                    'skipped_count' => $skipped,
                    'samples' => array_slice($skippedDiagnostics, 0, 50),
                ]);
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
    private function fetchAllCreditMemos(QuickBooksConnection $connection): array
    {
        $records = [];
        $startPosition = 1;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->quickBooks->query(
                $connection,
                'select * from CreditMemo startposition '.$startPosition.' maxresults '.self::PAGE_SIZE,
            );

            $rows = $response['CreditMemo'] ?? [];
            if (! is_array($rows)) {
                throw new QuickBooksApiException('QuickBooks returned an unexpected credit memo list.', 502);
            }

            if ($rows !== [] && ! array_is_list($rows)) {
                // A single associative record is not a valid list response for this query.
                throw new QuickBooksApiException('QuickBooks returned an unexpected credit memo list.', 502);
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new QuickBooksApiException('QuickBooks returned an unexpected credit memo list.', 502);
                }
            }

            $records = array_merge($records, array_values($rows));
            if (count($rows) < self::PAGE_SIZE) {
                return $records;
            }

            $startPosition += count($rows);
        }

        throw new QuickBooksApiException(
            'The QuickBooks credit memo list exceeds the current import safety limit. Please contact support.',
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
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    return null;
                }
            }
            return array_values($lines);
        }
        // QBO can represent a singleton line as an associative object.
        return [$lines];
    }

    /** @param array<int, array<string, mixed>> $lines
     *  @return array<int, array<string, mixed>>
     */
    private function linkedTransactions(array $lines): array
    {
        $linked = [];
        foreach ($lines as $line) {
            $transactions = $this->normaliseLines($line['LinkedTxn'] ?? []);
            if ($transactions === null) {
                continue;
            }
            foreach ($transactions as $transaction) {
                $linked[] = $transaction;
            }
        }
        return $linked;
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match)) {
            return null;
        }
        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]) ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
