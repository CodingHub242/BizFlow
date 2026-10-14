<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Imports read-only QuickBooks Online Invoice records into Bizflow's existing
 * invoices / invoice_items tables. It never creates payment records or stock movements.
 */
class QuickBooksInvoicesImporter
{
    private const ENTITY_TYPE = 'invoices';
    private const LOCAL_TYPE = Invoice::class;
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

        if (! Schema::hasTable('invoices')) {
            throw new QuickBooksApiException('The Bizflow invoices table is not available.', 409);
        }
        $lineTable = $this->invoiceLineTable();

        // Fetch all provider pages before opening the local transaction.
        $records = $this->fetchAllInvoices($connection);

        return DB::transaction(function () use ($connection, $tenantId, $branch, $userId, $records, $lineTable): array {
            $imported = 0;
            $alreadyImported = 0;
            $skipped = 0;
            $unresolvedLineReferences = 0;
            $unresolvedLineDiagnostics = [];

            foreach ($records as $record) {
                $quickBooksId = $this->nullableString($record['Id'] ?? null);
                $invoiceDate = $this->validDate($record['TxnDate'] ?? null);
                if ($quickBooksId === null || $invoiceDate === null) {
                    $skipped++;
                    continue;
                }

                $mapping = $this->sourceMapping($tenantId, (int) $connection->id, self::ENTITY_TYPE, $quickBooksId);
                if ($mapping && $mapping->local_type !== self::LOCAL_TYPE) {
                    throw new RuntimeException('A source mapping has an unexpected destination type.');
                }

                if ($mapping) {
                    $existing = DB::table('invoices')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $mapping->local_id)
                        ->first();
                    if ($existing) {
                        // Keep local edits and never append duplicate invoice lines on re-import.
                        $alreadyImported++;
                        continue;
                    }
                }

                $customerSourceId = $this->nullableString(data_get($record, 'CustomerRef.value'));
                if ($customerSourceId === null) {
                    $skipped++;
                    continue;
                }

                $customerMapping = $this->sourceMapping($tenantId, (int) $connection->id, 'customers', $customerSourceId);
                if (! $customerMapping || $customerMapping->local_type !== Customer::class) {
                    $skipped++;
                    continue;
                }

                $customerExists = Customer::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($customerMapping->local_id)
                    ->exists();
                if (! $customerExists) {
                    $skipped++;
                    continue;
                }

                $lines = $this->normaliseLines($record['Line'] ?? []);
                if ($lines === null) {
                    $skipped++;
                    continue;
                }

                // Resolve all source item references before writing the invoice.
                // Bizflow's invoice_items.catalog_item_id may be NOT NULL. In that
                // schema, importing an invoice with an unmapped line would otherwise
                // fail halfway through with a database constraint error. Skip the
                // whole invoice instead of creating an invoice with missing lines or
                // changing its accounting total. On schemas where the FK is nullable,
                // unresolved lines can still be preserved with their source data.
                $resolvedLineItems = [];
                $unresolvedLineIndexes = [];
                $catalogItemIdRequired = $this->catalogItemIdIsRequired($lineTable);
                foreach ($lines as $lineIndex => $line) {
                    $detailType = $this->nullableString($line['DetailType'] ?? null);

                    // Only SalesItemLineDetail represents a catalog item that must be
                    // resolved to Bizflow's catalog_items table. SubTotalLineDetail,
                    // DiscountLineDetail and other QuickBooks line types are not items.
                    // They are preserved in the source mapping metadata below.
                    if ($detailType !== 'SalesItemLineDetail') {
                        continue;
                    }

                    $itemSourceId = $this->nullableString(data_get($line, 'SalesItemLineDetail.ItemRef.value'));
                    $itemSourceName = $this->nullableString(data_get($line, 'SalesItemLineDetail.ItemRef.name'));
                    if ($itemSourceId === null) {
                        $unresolvedLineIndexes[] = $lineIndex;
                        $unresolvedLineDiagnostics[] = [
                            'invoice_quickbooks_id' => $quickBooksId,
                            'line_id' => $this->nullableString($line['Id'] ?? null),
                            'detail_type' => $detailType,
                            'item_ref_id' => null,
                            'item_ref_name' => $itemSourceName,
                            'reason' => 'missing_item_ref',
                        ];
                        continue;
                    }

                    $itemMapping = $this->sourceMapping($tenantId, (int) $connection->id, 'catalog_items', $itemSourceId);
                    if (! $itemMapping || $itemMapping->local_type !== CatalogItem::class) {
                        $unresolvedLineIndexes[] = $lineIndex;
                        $unresolvedLineDiagnostics[] = [
                            'invoice_quickbooks_id' => $quickBooksId,
                            'line_id' => $this->nullableString($line['Id'] ?? null),
                            'detail_type' => $detailType,
                            'item_ref_id' => $itemSourceId,
                            'item_ref_name' => $itemSourceName,
                            'reason' => ! $itemMapping ? 'mapping_not_found' : 'mapping_destination_type_mismatch',
                        ];
                        continue;
                    }

                    $catalogItem = CatalogItem::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($itemMapping->local_id)
                        ->first();
                    if (! $catalogItem) {
                        $unresolvedLineIndexes[] = $lineIndex;
                        $unresolvedLineDiagnostics[] = [
                            'invoice_quickbooks_id' => $quickBooksId,
                            'line_id' => $this->nullableString($line['Id'] ?? null),
                            'detail_type' => $detailType,
                            'item_ref_id' => $itemSourceId,
                            'item_ref_name' => $itemSourceName,
                            'reason' => 'mapped_local_catalog_item_missing',
                        ];
                        continue;
                    }

                    $resolvedLineItems[$lineIndex] = [
                        'id' => $catalogItem->id,
                        'name' => $catalogItem->name,
                    ];
                }

                $unresolvedLineReferences += count($unresolvedLineIndexes);
                if ($catalogItemIdRequired && $unresolvedLineIndexes !== []) {
                    $skipped++;
                    continue;
                }

                $total = $this->amount($record['TotalAmt'] ?? 0);
                $balance = max(0, min($total, $this->amount($record['Balance'] ?? $total)));
                $tax = $this->amount(data_get($record, 'TxnTaxDetail.TotalTax') ?? 0);
                $paidToDate = max(0, round($total - $balance, 2));
                $sourceDocNumber = $this->nullableString($record['DocNumber'] ?? null);
                $invoiceNumber = $this->uniqueInvoiceNumber($tenantId, $sourceDocNumber, $quickBooksId);
                $now = now();

                $invoiceAttributes = [
                    'tenant_id' => $tenantId,
                    'branch_id' => $branch->id,
                    'customer_id' => (int) $customerMapping->local_id,
                    'created_by' => $userId,
                    'invoice_number' => $invoiceNumber,
                    'invoice_date' => $invoiceDate,
                    'issue_date' => $invoiceDate,
                    'date' => $invoiceDate,
                    'due_date' => $this->validDate($record['DueDate'] ?? null),
                    'subtotal' => round($total - $tax, 2),
                    'tax_amount' => $tax,
                    'tax' => $tax,
                    'total' => $total,
                    'total_amount' => $total,
                    'amount_paid' => $paidToDate,
                    'balance_due' => $balance,
                    'balance' => $balance,
                    'currency' => $this->currencyCode(data_get($record, 'CurrencyRef.value')),
                    'status' => $balance <= 0 ? 'paid' : 'sent',
                    'payment_status' => $balance <= 0 ? 'paid' : ($balance < $total ? 'partially_paid' : 'unpaid'),
                    'source_status' => $this->nullableString($record['TxnStatus'] ?? null),
                    'notes' => $this->nullableString($record['PrivateNote'] ?? null),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $invoiceId = DB::table('invoices')->insertGetId($this->existingColumns('invoices', $invoiceAttributes));

                foreach ($lines as $index => $line) {
                    $detailType = $this->nullableString($line['DetailType'] ?? null);

                    // When catalog_item_id is required, only catalog-backed sales
                    // lines can be stored in invoice_items. Do not insert subtotal or
                    // discount summary rows with a NULL catalog_item_id; their exact
                    // source representation is retained in mapping metadata.
                    if ($catalogItemIdRequired && $detailType !== 'SalesItemLineDetail') {
                        continue;
                    }

                    $itemSourceName = $this->nullableString(data_get($line, 'SalesItemLineDetail.ItemRef.name'));
                    $resolvedItem = $resolvedLineItems[$index] ?? null;
                    $catalogItemId = $resolvedItem['id'] ?? null;
                    $catalogItemName = $resolvedItem['name'] ?? null;

                    $quantity = $this->numericOrNull(data_get($line, 'SalesItemLineDetail.Qty')) ?? 1.0;
                    $unitPrice = $this->numericOrNull(data_get($line, 'SalesItemLineDetail.UnitPrice'));
                    $itemName = $catalogItemName ?? $itemSourceName;
                    if ($itemName === null && $detailType === 'DiscountLineDetail') {
                        $itemName = 'Discount';
                    }
                    $amount = $this->amount($line['Amount'] ?? 0);
                    $lineTax = $this->amount($line['TaxAmount'] ?? data_get($line, 'SalesItemLineDetail.TaxAmount') ?? 0);
                    $discount = $this->amount(
                        $line['DiscountAmt']
                            ?? data_get($line, 'SalesItemLineDetail.DiscountAmt')
                            ?? data_get($line, 'DiscountLineDetail.DiscountAmt')
                            ?? 0,
                    );

                    $lineAttributes = [
                        'tenant_id' => $tenantId,
                        'invoice_id' => $invoiceId,
                        'catalog_item_id' => $catalogItemId,
                        'source_line_id' => $this->nullableString($line['Id'] ?? null),
                        'line_type' => $detailType,
                        'item_name' => $itemName,
                        'name' => $itemName,
                        'description' => $this->nullableString($line['Description'] ?? null) ?? $itemName,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice ?? $amount,
                        'discount' => $discount,
                        'tax' => $lineTax,
                        'tax_amount' => $lineTax,
                        'line_total' => $amount,
                        'total' => $amount,
                        'amount' => $amount,
                        'line_order' => $index + 1,
                        'source_data' => json_encode($line, JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    DB::table($lineTable)->insert($this->existingColumns($lineTable, $lineAttributes));
                }

                $payloadHash = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
                $mappingData = [
                    'tenant_id' => $tenantId,
                    'quickbooks_connection_id' => $connection->id,
                    'entity_type' => self::ENTITY_TYPE,
                    'quickbooks_id' => $quickBooksId,
                    'local_type' => self::LOCAL_TYPE,
                    'local_id' => $invoiceId,
                    'payload_hash' => $payloadHash,
                    'imported_at' => now(),
                    'metadata' => [
                        'source_doc_number' => $sourceDocNumber,
                        'stored_invoice_number' => $invoiceNumber,
                        'source_customer_id' => $customerSourceId,
                        'source_customer_name' => $this->nullableString(data_get($record, 'CustomerRef.name')),
                        'source_line_count' => count($lines),
                        // Preserve all original QuickBooks line objects, including non-item
                        // subtotal/discount lines that cannot be inserted into a table whose
                        // catalog_item_id foreign key is NOT NULL.
                        'source_lines' => $lines,
                        'source_total' => $total,
                        'source_balance' => $balance,
                        'source_paid_to_date' => $paidToDate,
                        'source_tax_detail' => $record['TxnTaxDetail'] ?? null,
                        'branch_id' => $branch->id,
                        // This is an aggregate only. The later Payments import must link
                        // real payment records without blindly incrementing this amount.
                        'payment_aggregate_preserved' => true,
                    ],
                ];
                if ($mapping) {
                    $mapping->forceFill($mappingData)->save();
                } else {
                    QuickBooksImportMapping::query()->create($mappingData);
                }
                $imported++;
            }

            if ($unresolvedLineDiagnostics !== []) {
                Log::warning('QuickBooks invoice line-reference diagnostics.', [
                    'connection_id' => $connection->id,
                    'tenant_id' => $tenantId,
                    'unresolved_count' => count($unresolvedLineDiagnostics),
                    'samples' => array_slice($unresolvedLineDiagnostics, 0, 50),
                ]);
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
    private function fetchAllInvoices(QuickBooksConnection $connection): array
    {
        $records = [];
        $startPosition = 1;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->quickBooks->query(
                $connection,
                'select * from Invoice startposition '.$startPosition.' maxresults '.self::PAGE_SIZE,
            );
            $rows = $response['Invoice'] ?? [];
            if (! is_array($rows)) {
                throw new QuickBooksApiException('QuickBooks returned an unexpected invoice list.', 502);
            }
            $rows = array_values(array_filter($rows, 'is_array'));
            $records = array_merge($records, $rows);
            if (count($rows) < self::PAGE_SIZE) {
                return $records;
            }
            $startPosition += count($rows);
        }
        throw new QuickBooksApiException(
            'The QuickBooks invoice list exceeds the current import safety limit. Please contact support.',
            413,
        );
    }

    private function invoiceLineTable(): string
    {
        if (Schema::hasTable('invoice_items')) {
            return 'invoice_items';
        }
        if (Schema::hasTable('invoice_lines')) {
            return 'invoice_lines';
        }
        throw new QuickBooksApiException('The Bizflow invoice line-items table is not available.', 409);
    }

    private function uniqueInvoiceNumber(int $tenantId, ?string $sourceNumber, string $quickBooksId): string
    {
        $candidate = $sourceNumber ?? ('QBO-'.$quickBooksId);
        if (! Schema::hasColumn('invoices', 'invoice_number')) {
            return $candidate;
        }
        $exists = DB::table('invoices')->where('tenant_id', $tenantId)->where('invoice_number', $candidate)->exists();
        if (! $exists) {
            return $candidate;
        }

        // Invoice numbers are unique in Bizflow. Preserve duplicate QBO DocNumbers
        // by storing a deterministic unique suffix; the original remains in mapping metadata.
        $base = substr($candidate, 0, 30);
        $safeSourceId = substr($quickBooksId, 0, 24);
        $candidate = $base.'-QBO-'.$safeSourceId;
        $suffix = 1;
        while (DB::table('invoices')->where('tenant_id', $tenantId)->where('invoice_number', $candidate)->exists()) {
            $candidate = $base.'-QBO-'.$safeSourceId.'-'.$suffix++;
        }
        return $candidate;
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
        return isset($lines['DetailType']) || isset($lines['Amount']) ? [$lines] : null;
    }

    /** Whether invoice lines require a non-null catalog item foreign key. */
    private function catalogItemIdIsRequired(string $table): bool
    {
        if (! Schema::hasColumn($table, 'catalog_item_id')) {
            return false;
        }

        foreach (Schema::getColumns($table) as $column) {
            if (($column['name'] ?? null) === 'catalog_item_id') {
                return ! (bool) ($column['nullable'] ?? false);
            }
        }

        // Fail safely when a column exists but its nullability cannot be determined.
        return true;
    }

    /** Keep only columns supported by the project's existing schema. */
    private function existingColumns(string $table, array $attributes): array
    {
        $allowed = array_flip(Schema::getColumnListing($table));
        return array_intersect_key($attributes, $allowed);
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
        return $value !== null && strlen($value) <= 3 ? strtoupper($value) : null;
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
