<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Imports QuickBooks Online Payment records into Bizflow's existing
 * invoice_payments table. Invoice aggregate paid/balance fields are not changed:
 * the Invoice import has already preserved the source totals, so changing them
 * here would double-count paid amounts.
 */
class QuickBooksPaymentsImporter
{
    private const ENTITY_TYPE = 'payments';
    private const LOCAL_TYPE = 'App\\Models\\InvoicePayment';
    private const PAGE_SIZE = 1000;
    private const MAX_PAGES = 50;

    public function __construct(private QuickBooksAccountingApiService $quickBooks)
    {
    }

    public function import(QuickBooksConnection $connection, int $tenantId, int $userId): array
    {
        if ((int) $connection->tenant_id !== $tenantId) {
            throw new RuntimeException('The QuickBooks connection does not belong to this tenant.');
        }

        if ($connection->connection_status !== 'connected') {
            throw new QuickBooksApiException('This QuickBooks connection is not active.', 409);
        }

        if (! Schema::hasTable('invoices') || ! Schema::hasTable('invoice_payments')) {
            throw new QuickBooksApiException('Bizflow invoice payment tables are not available.', 409);
        }

        $records = $this->fetchAllPayments($connection);

        $paymentMethodNames = $this->fetchPaymentMethodNames(
            $connection,
            $records
        );

        return DB::transaction(function () use ($connection, $tenantId, $userId, $records, $paymentMethodNames): array {
            $imported = 0;
            $alreadyImported = 0;
            $skipped = 0;
            $allocationsCreated = 0;
            $unresolvedInvoiceReferences = 0;
            $unappliedOrUnlinkedPayments = 0;
            $sourceAmountDifferences = 0;
            $skippedDiagnostics = [];

            foreach ($records as $record) {
                $quickBooksId = $this->nullableString($record['Id'] ?? null);
                $paymentDate = $this->validDate($record['TxnDate'] ?? null);
                $totalAmount = $this->numericOrNull($record['TotalAmt'] ?? null);

                if ($quickBooksId === null || $paymentDate === null || $totalAmount === null || $totalAmount <= 0) {
                    $skipped++;
                    $fieldIssues = array_values(array_filter([
                        $quickBooksId === null ? 'Id' : null,
                        $paymentDate === null ? 'TxnDate' : null,
                        $totalAmount === null ? 'TotalAmt' : null,
                        $totalAmount !== null && $totalAmount <= 0 ? 'TotalAmt_must_be_positive' : null,
                    ]));
                    $skippedDiagnostics[] = [
                        'quickbooks_payment_id' => $quickBooksId,
                        'reason' => 'missing_or_invalid_required_fields',
                        'field_issues' => $fieldIssues,
                    ];
                    continue;
                }

                $mapping = $this->sourceMapping($tenantId, (int) $connection->id, self::ENTITY_TYPE, $quickBooksId);
                if ($mapping && $mapping->local_type !== self::LOCAL_TYPE) {
                    throw new RuntimeException('A payment source mapping has an unexpected destination type.');
                }

                if ($mapping) {
                    $mappedPaymentExists = DB::table('invoice_payments')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $mapping->local_id)
                        ->exists();

                    $metadata = $this->mappingMetadata($mapping->metadata);
                    $mappedIds = array_values(array_filter(array_map('intval', $metadata['local_payment_ids'] ?? [])));
                    $allMappedRowsExist = $mappedIds !== [] && DB::table('invoice_payments')
                        ->where('tenant_id', $tenantId)
                        ->whereIn('id', $mappedIds)
                        ->count() === count($mappedIds);

                    if ($mappedPaymentExists || $allMappedRowsExist) {
                        $alreadyImported++;
                        continue;
                    }
                }

                $customerSourceId = $this->nullableString(data_get($record, 'CustomerRef.value'));
                if ($customerSourceId === null) {
                    $skipped++;
                    $skippedDiagnostics[] = [
                        'quickbooks_payment_id' => $quickBooksId,
                        'reason' => 'missing_customer_ref',
                    ];
                    continue;
                }

                $customerMapping = $this->sourceMapping($tenantId, (int) $connection->id, 'customers', $customerSourceId);
                if (! $customerMapping || $customerMapping->local_type !== Customer::class) {
                    $skipped++;
                    $skippedDiagnostics[] = [
                        'quickbooks_payment_id' => $quickBooksId,
                        'source_customer_id' => $customerSourceId,
                        'reason' => ! $customerMapping ? 'customer_mapping_not_found' : 'customer_mapping_destination_type_mismatch',
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
                        'quickbooks_payment_id' => $quickBooksId,
                        'source_customer_id' => $customerSourceId,
                        'mapped_local_customer_id' => (int) $customerMapping->local_id,
                        'reason' => 'mapped_local_customer_missing',
                    ];
                    continue;
                }

                $lines = $this->normaliseLines($record['Line'] ?? []);
                if ($lines === null) {
                    $skipped++;
                    $skippedDiagnostics[] = [
                        'quickbooks_payment_id' => $quickBooksId,
                        'reason' => 'invalid_line_structure',
                        'line_payload_type' => get_debug_type($record['Line'] ?? null),
                    ];
                    continue;
                }

                // Build allocations by QBO Invoice ID first. If any invoice cannot
                // be resolved, skip the whole source payment rather than partially
                // importing a payment and losing its accounting meaning.
                $sourceAllocations = [];
                $allocationProblemCount = 0;
                $ambiguousAllocation = false;

                foreach ($lines as $line) {
                    $linkedTransactions = $this->normaliseLines($line['LinkedTxn'] ?? []);
                    if ($linkedTransactions === null || $linkedTransactions === []) {
                        continue;
                    }

                    $invoiceTransactions = [];
                    foreach ($linkedTransactions as $linkedTransaction) {
                        if (($linkedTransaction['TxnType'] ?? null) !== 'Invoice') {
                            continue;
                        }
                        $invoiceSourceId = $this->nullableString($linkedTransaction['TxnId'] ?? null);
                        if ($invoiceSourceId === null) {
                            $allocationProblemCount++;
                            continue;
                        }
                        $invoiceTransactions[$invoiceSourceId] = $linkedTransaction;
                    }

                    if ($invoiceTransactions === []) {
                        continue;
                    }

                    $lineAmount = $this->numericOrNull($line['Amount'] ?? null);
                    if (count($invoiceTransactions) > 1) {
                        // This local schema needs a concrete amount per invoice.
                        // Do not invent a split when QBO provides several invoice
                        // links on one line without per-link allocation amounts.
                        $allLinksHaveAmounts = true;
                        foreach ($invoiceTransactions as $sourceId => $linkedTransaction) {
                            $linkedAmount = $this->numericOrNull($linkedTransaction['Amount'] ?? null);
                            if ($linkedAmount === null || $linkedAmount <= 0) {
                                $allLinksHaveAmounts = false;
                                break;
                            }
                            $sourceAllocations[$sourceId] = ($sourceAllocations[$sourceId] ?? 0.0) + $linkedAmount;
                        }
                        if (! $allLinksHaveAmounts) {
                            $ambiguousAllocation = true;
                            $allocationProblemCount += count($invoiceTransactions);
                            break;
                        }
                        continue;
                    }

                    $invoiceSourceId = (string) array_key_first($invoiceTransactions);
                    if ($lineAmount === null) {
                        $allocationProblemCount++;
                        continue;
                    }
                    if ($lineAmount <= 0) {
                        continue;
                    }
                    $sourceAllocations[$invoiceSourceId] = ($sourceAllocations[$invoiceSourceId] ?? 0.0) + $lineAmount;
                }

                $unappliedAmount = max(0, round((float) ($this->numericOrNull($record['UnappliedAmt'] ?? null) ?? 0), 2));
                if ($ambiguousAllocation || $sourceAllocations === [] || $allocationProblemCount > 0) {
                    $skipped++;
                    $unresolvedInvoiceReferences += $allocationProblemCount;
                    if ($sourceAllocations === [] && $unappliedAmount > 0) {
                        $unappliedOrUnlinkedPayments++;
                    } elseif ($sourceAllocations === []) {
                        $unappliedOrUnlinkedPayments++;
                    }
                    $skippedDiagnostics[] = [
                        'quickbooks_payment_id' => $quickBooksId,
                        'reason' => $ambiguousAllocation
                            ? 'ambiguous_invoice_allocation'
                            : ($sourceAllocations === []
                                ? ($unappliedAmount > 0 ? 'unapplied_or_unlinked_payment' : 'no_invoice_allocations_found')
                                : 'incomplete_invoice_link_reference'),
                        'source_line_count' => count($lines),
                        'source_allocation_count' => count($sourceAllocations),
                        'allocation_problem_count' => $allocationProblemCount,
                        'unapplied_amount' => $unappliedAmount,
                    ];
                    continue;
                }

                $resolvedAllocations = [];
                foreach ($sourceAllocations as $invoiceSourceId => $allocatedAmount) {
                    $invoiceMapping = $this->sourceMapping($tenantId, (int) $connection->id, 'invoices', (string) $invoiceSourceId);
                    if (! $invoiceMapping || $invoiceMapping->local_type !== Invoice::class) {
                        $allocationProblemCount++;
                        continue;
                    }

                    $invoice = DB::table('invoices')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $invoiceMapping->local_id)
                        ->first();
                    if (! $invoice || ! isset($invoice->customer_id) || (int) $invoice->customer_id !== (int) $customer->id) {
                        $allocationProblemCount++;
                        continue;
                    }

                    $resolvedAllocations[] = [
                        'source_invoice_id' => (string) $invoiceSourceId,
                        'invoice_id' => (int) $invoice->id,
                        'amount' => round((float) $allocatedAmount, 2),
                    ];
                }

                if ($allocationProblemCount > 0 || $resolvedAllocations === []) {
                    $skipped++;
                    $unresolvedInvoiceReferences += max(1, $allocationProblemCount);
                    $skippedDiagnostics[] = [
                        'quickbooks_payment_id' => $quickBooksId,
                        'reason' => $resolvedAllocations === [] ? 'no_resolved_invoice_allocations' : 'invoice_mapping_not_found_or_customer_mismatch',
                        'source_invoice_ids' => array_values(array_map('strval', array_keys($sourceAllocations))),
                        'allocation_problem_count' => $allocationProblemCount,
                    ];
                    continue;
                }

                $allocationTotal = round(array_sum(array_column($resolvedAllocations, 'amount')), 2);
                if (abs($allocationTotal - max(0, round((float) $totalAmount - $unappliedAmount, 2))) > 0.02) {
                    // Preserve the difference for reconciliation but do not alter
                    // source amounts or local invoice aggregates.
                    $sourceAmountDifferences++;
                }

               $methodId = $this->nullableString(
                    data_get($record, 'PaymentMethodRef.value')
                );

                $methodLabel = $this->nullableString(
                    data_get($record, 'PaymentMethodRef.name')
                );

                if ($methodLabel === null && $methodId !== null) {
                    $methodLabel = $paymentMethodNames[$methodId] ?? null;
                }

                $methodValue = $this->normalisePaymentMethod($methodLabel);
                $checkNumber = $this->nullableString($record['CheckNum'] ?? null);
                $privateNote = $this->nullableString($record['PrivateNote'] ?? $record['Memo'] ?? null);
                $now = now();
                $localPaymentIds = [];
                $allocationMetadata = [];

                foreach ($resolvedAllocations as $allocation) {
                    $reference = $this->allocationReference(
                        (int) $connection->id,
                        $quickBooksId,
                        $allocation['source_invoice_id'],
                    );

                    // A reference collision without a source mapping is not safe
                    // to reuse: it could belong to another payment.
                    if (DB::table('invoice_payments')
                        ->where('tenant_id', $tenantId)
                        ->where('reference', $reference)
                        ->exists()) {
                        throw new RuntimeException('A QuickBooks payment allocation reference is already in use.');
                    }

                    $notes = 'Imported from QuickBooks Online Payment '.$quickBooksId.'.';
                    if ($privateNote !== null) {
                        $notes .= ' '.$privateNote;
                    }
                    if ($checkNumber !== null) {
                        $notes .= ' Source check number: '.$checkNumber.'.';
                    }

                    $paymentAttributes = [
                        'tenant_id' => $tenantId,
                        'invoice_id' => $allocation['invoice_id'],
                        'recorded_by' => $userId,
                        'amount' => $allocation['amount'],
                        'method' => $methodValue,
                        'reference' => $reference,
                        'notes' => $notes,
                        'paid_at' => $paymentDate.' 00:00:00',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $paymentId = DB::table('invoice_payments')->insertGetId(
                        $this->existingColumns('invoice_payments', $paymentAttributes),
                    );
                    $localPaymentIds[] = (int) $paymentId;
                    $allocationMetadata[] = [
                        'quickbooks_invoice_id' => $allocation['source_invoice_id'],
                        'local_invoice_id' => $allocation['invoice_id'],
                        'local_payment_id' => (int) $paymentId,
                        'amount' => $allocation['amount'],
                        'reference' => $reference,
                    ];
                    $allocationsCreated++;
                }

                $payloadHash = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
                $mappingData = [
                    'tenant_id' => $tenantId,
                    'quickbooks_connection_id' => $connection->id,
                    'entity_type' => self::ENTITY_TYPE,
                    'quickbooks_id' => $quickBooksId,
                    'local_type' => self::LOCAL_TYPE,
                    'local_id' => $localPaymentIds[0],
                    'payload_hash' => $payloadHash,
                    'imported_at' => now(),
                    'metadata' => [
                        'source_customer_id' => $customerSourceId,
                        'source_customer_name' => $this->nullableString(data_get($record, 'CustomerRef.name')),
                        'source_total_amount' => round((float) $totalAmount, 2),
                        'source_unapplied_amount' => $unappliedAmount,
                        'source_payment_method_id' => $methodId,                        
                        'source_payment_method_name' => $methodLabel,
                        'source_check_number' => $checkNumber,
                        'source_line_count' => count($lines),
                        'source_lines' => $lines,
                        'allocation_total' => $allocationTotal,
                        'allocation_amount_difference' => round(max(0, (float) $totalAmount - $unappliedAmount) - $allocationTotal, 2),
                        'local_payment_ids' => $localPaymentIds,
                        'allocations' => $allocationMetadata,
                        'invoice_aggregate_already_imported' => true,
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
                Log::warning('QuickBooks payments import skipped records.', [
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
                'allocation_count' => $allocationsCreated,
                'unresolved_invoice_reference_count' => $unresolvedInvoiceReferences,
                'unapplied_or_unlinked_payment_count' => $unappliedOrUnlinkedPayments,
                'source_amount_difference_count' => $sourceAmountDifferences,
            ];
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAllPayments(QuickBooksConnection $connection): array
    {
        $records = [];
        $startPosition = 1;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->quickBooks->query(
                $connection,
                'select * from Payment startposition '.$startPosition.' maxresults '.self::PAGE_SIZE,
            );

            $rows = $response['Payment'] ?? [];
            if (! is_array($rows)) {
                throw new QuickBooksApiException('QuickBooks returned an unexpected payment list.', 502);
            }

            $rows = array_values(array_filter($rows, 'is_array'));
            $records = array_merge($records, $rows);
            if (count($rows) < self::PAGE_SIZE) {
                return $records;
            }
            $startPosition += count($rows);
        }

        throw new QuickBooksApiException(
            'The QuickBooks payment list exceeds the current import safety limit. Please contact support.',
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

     /**
     * Resolve QuickBooks PaymentMethod IDs to their names.
     *
     * @param array<int, array<string, mixed>> $payments
     * @return array<string, string>
     */
    private function fetchPaymentMethodNames(
        QuickBooksConnection $connection,
        array $payments
    ): array {
        $needsLookup = false;

        foreach ($payments as $payment) {
            $methodId = $this->nullableString(
                data_get($payment, 'PaymentMethodRef.value')
            );

            $methodName = $this->nullableString(
                data_get($payment, 'PaymentMethodRef.name')
            );

            if ($methodId !== null && $methodName === null) {
                $needsLookup = true;
                break;
            }
        }

        if (! $needsLookup) {
            return [];
        }

        $methodNames = [];
        $startPosition = 1;

        for ($page = 0; $page < 10; $page++) {
            $response = $this->quickBooks->query(
                $connection,
                'select * from PaymentMethod startposition '
                    .$startPosition.' maxresults '.self::PAGE_SIZE
            );

            $rows = $response['PaymentMethod'] ?? [];

            if (! is_array($rows)) {
                throw new QuickBooksApiException(
                    'QuickBooks returned an unexpected payment-method list.',
                    502
                );
            }

            $rows = array_values(
                array_filter($rows, 'is_array')
            );

            foreach ($rows as $row) {
                $id = $this->nullableString($row['Id'] ?? null);
                $name = $this->nullableString($row['Name'] ?? null);

                if ($id !== null && $name !== null) {
                    $methodNames[$id] = $name;
                }
            }

            if (count($rows) < self::PAGE_SIZE) {
                return $methodNames;
            }

            $startPosition += count($rows);
        }

        throw new QuickBooksApiException(
            'The QuickBooks payment-method list exceeds the current lookup limit.',
            413
        );
    }

    public function reconcileExistingPaymentMethods(QuickBooksConnection $connection,int $tenantId,bool $apply = false): array 
    {
        if ((int) $connection->tenant_id !== $tenantId) {
            throw new RuntimeException(
                'The QuickBooks connection does not belong to this tenant.'
            );
        }

        if ($connection->connection_status !== 'connected') {
            throw new QuickBooksApiException(
                'This QuickBooks connection is not active.',
                409
            );
        }

        $records = $this->fetchAllPayments($connection);

        // Include previously stored IDs so we can resolve them even if a
        // current Payment response no longer includes its method reference.
        $existingMappings = QuickBooksImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('quickbooks_connection_id', $connection->id)
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('local_type', self::LOCAL_TYPE)
            ->get();

        $lookupRecords = $records;

        foreach ($existingMappings as $existingMapping) {
            $metadata = $this->mappingMetadata($existingMapping->metadata);

            $methodId = $this->nullableString(
                $metadata['source_payment_method_id'] ?? null
            );

            if ($methodId !== null) {
                $lookupRecords[] = [
                    'PaymentMethodRef' => ['value' => $methodId],
                ];
            }
        }

        $paymentMethodNames = $this->fetchPaymentMethodNames(
            $connection,
            $lookupRecords
        );

        return DB::transaction(function () use (
            $records,
            $connection,
            $tenantId,
            $paymentMethodNames,
            $apply
        ): array {
            $mappingsChecked = 0;
            $unresolvedMethods = 0;
            $updatedPaymentRows = 0;
            $updatedMappings = 0;
            $candidates = [];

            foreach ($records as $record) {
                $quickBooksId = $this->nullableString(
                    $record['Id'] ?? null
                );

                if ($quickBooksId === null) {
                    continue;
                }

                $mapping = $this->sourceMapping(
                    $tenantId,
                    (int) $connection->id,
                    self::ENTITY_TYPE,
                    $quickBooksId
                );

                if (! $mapping) {
                    continue;
                }

                if ($mapping->local_type !== self::LOCAL_TYPE) {
                    throw new RuntimeException(
                        'A payment source mapping has an unexpected destination type.'
                    );
                }

                $mappingsChecked++;
                $metadata = $this->mappingMetadata($mapping->metadata);

                $methodId = $this->nullableString(
                    data_get($record, 'PaymentMethodRef.value')
                );

                $methodId ??= $this->nullableString(
                    $metadata['source_payment_method_id'] ?? null
                );

                $methodLabel = $this->nullableString(
                    data_get($record, 'PaymentMethodRef.name')
                );

                if ($methodLabel === null && $methodId !== null) {
                    $methodLabel = $paymentMethodNames[$methodId] ?? null;
                }

                if ($methodLabel === null) {
                    $methodLabel = $this->nullableString(
                        $metadata['source_payment_method_name'] ?? null
                    );
                }

                if ($methodLabel === null) {
                    $unresolvedMethods++;
                    continue;
                }

                $targetMethod = $this->normalisePaymentMethod($methodLabel);

                $mappedIds = $metadata['local_payment_ids'] ?? [];

                if (! is_array($mappedIds)) {
                    $mappedIds = [];
                }

                $paymentIds = array_values(array_unique(array_filter(
                    array_map(
                        'intval',
                        array_merge($mappedIds, [(int) $mapping->local_id])
                    ),
                    fn (int $id): bool => $id > 0
                )));

                if ($paymentIds === []) {
                    continue;
                }

                $localRows = DB::table('invoice_payments')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $paymentIds)
                    ->get(['id', 'method']);

                // Only automatically correct rows that still say "other".
                // Never overwrite a more specific method already stored.
                $eligibleRows = $targetMethod === 'other'
                    ? 0
                    : $localRows->filter(
                        fn ($row): bool =>
                            strtolower(trim((string) $row->method)) === 'other'
                    )->count();

                if ($eligibleRows > 0) {
                    $candidates[] = [
                        'quickbooks_id' => $quickBooksId,
                        'source_method_id' => $methodId ?? 'Unavailable',
                        'source_method_name' => $methodLabel,
                        'bizflow_method' => $targetMethod,
                        'eligible_rows' => $eligibleRows,
                    ];
                }

                if (! $apply) {
                    continue;
                }

                if ($eligibleRows > 0) {
                    $updatedPaymentRows += DB::table('invoice_payments')
                        ->where('tenant_id', $tenantId)
                        ->whereIn('id', $paymentIds)
                        ->where('method', 'other')
                        ->update([
                            'method' => $targetMethod,
                            'updated_at' => now(),
                        ]);
                }

                $metadataChanged = false;

                if (($metadata['source_payment_method_id'] ?? null) !== $methodId) {
                    $metadata['source_payment_method_id'] = $methodId;
                    $metadataChanged = true;
                }

                if (
                    ($metadata['source_payment_method_name'] ?? null)
                    !== $methodLabel
                ) {
                    $metadata['source_payment_method_name'] = $methodLabel;
                    $metadataChanged = true;
                }

                if ($metadataChanged) {
                    $mapping->forceFill([
                        'metadata' => $metadata,
                    ])->save();

                    $updatedMappings++;
                }
            }

            return [
                'applied' => $apply,
                'payment_records_scanned' => count($records),
                'mappings_checked' => $mappingsChecked,
                'unresolved_methods' => $unresolvedMethods,
                'candidates' => $candidates,
                'eligible_payment_rows' => array_sum(
                    array_column($candidates, 'eligible_rows')
                ),
                'updated_payment_rows' => $updatedPaymentRows,
                'updated_mappings' => $updatedMappings,
            ];
        }, 3);
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
        return $lines !== [] ? [$lines] : [];
    }

    private function normalisePaymentMethod(?string $sourceName): string
    {
        $name = strtolower(trim((string) $sourceName));
        $candidate = match (true) {
            $name === 'cash' || str_contains($name, 'cash') => 'cash',
            str_contains($name, 'mobile') || str_contains($name, 'm-pesa') || str_contains($name, 'momo') => 'mobile_money',
            str_contains($name, 'card') || str_contains($name, 'credit') => 'card',
            str_contains($name, 'bank') || str_contains($name, 'transfer') || str_contains($name, 'wire') || str_contains($name, 'ach') => 'bank_transfer',
            str_contains($name, 'check') || str_contains($name, 'cheque') => 'cheque',
            default => 'other',
        };

        $enumClass = 'App\\InvoicePaymentMethod';
        if (! enum_exists($enumClass)) {
            return $candidate;
        }

        $supported = array_map(static fn ($case) => $case->value, $enumClass::cases());
        if (in_array($candidate, $supported, true)) {
            return $candidate;
        }
        foreach (['other', 'cash', 'bank_transfer', 'mobile_money'] as $fallback) {
            if (in_array($fallback, $supported, true)) {
                return $fallback;
            }
        }
        return (string) ($supported[0] ?? 'cash');
    }

    private function allocationReference(int $connectionId, string $paymentId, string $invoiceId): string
    {
        return substr('QBO-C'.$connectionId.'-PAY-'.$paymentId.'-INV-'.$invoiceId, 0, 255);
    }

    private function mappingMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

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
