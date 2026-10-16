<?php

namespace App\Services;

use App\Models\CreditMemo;
use App\Models\CreditMemoApplication;
use App\Models\Invoice;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Reconciles CreditMemo-to-Invoice applications from QBO Payment transactions.
 *
 * In QBO, a zero-total Payment can represent a credit memo applied to an invoice:
 * its Line entries link to both the Invoice and CreditMemo, while TotalAmt is 0.
 * Such records are NOT imported as cash payments. This service records the credit
 * application separately and never changes invoice totals or invoice_payments.
 */
class QuickBooksCreditMemoApplicationsReconciler
{
    private const PAGE_SIZE = 1000;
    private const MAX_PAGES = 50;
    private const INVOICE_TYPE = Invoice::class;
    private const CREDIT_MEMO_TYPE = CreditMemo::class;

    public function __construct(private QuickBooksAccountingApiService $quickBooks)
    {
    }

    public function reconcile(QuickBooksConnection $connection, int $tenantId): array
    {
        if ((int) $connection->tenant_id !== $tenantId) {
            throw new RuntimeException('The QuickBooks connection does not belong to this tenant.');
        }

        if ($connection->connection_status !== 'connected') {
            throw new QuickBooksApiException('This QuickBooks connection is not active.', 409);
        }

        foreach (['credit_memos', 'credit_memo_applications', 'invoices', 'quickbooks_import_mappings'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new QuickBooksApiException('Bizflow credit application reconciliation tables are not available.', 409);
            }
        }

        // Fetch all source records before opening a local transaction.
        $payments = $this->fetchAllPayments($connection);

        return DB::transaction(function () use ($connection, $tenantId, $payments): array {
            $created = 0;
            $alreadyApplied = 0;
            $skipped = 0;
            $diagnostics = [];

            foreach ($payments as $payment) {
                $paymentId = $this->nullableString($payment['Id'] ?? null);
                if ($paymentId === null) {
                    $skipped++;
                    $diagnostics[] = ['reason' => 'payment_missing_id'];
                    continue;
                }

                $pair = $this->extractSingleCreditApplication($payment);
                if ($pair['status'] !== 'candidate') {
                    if ($pair['status'] === 'not_applicable') {
                        continue;
                    }
                    $skipped++;
                    $diagnostics[] = [
                        'quickbooks_payment_id' => $paymentId,
                        'reason' => $pair['reason'],
                    ];
                    continue;
                }

                $creditMemoSourceId = $pair['credit_memo_id'];
                $invoiceSourceId = $pair['invoice_id'];
                $amount = $pair['amount'];

                $creditMemoMapping = $this->sourceMapping($tenantId, (int) $connection->id, 'credit_memos', $creditMemoSourceId);
                $invoiceMapping = $this->sourceMapping($tenantId, (int) $connection->id, 'invoices', $invoiceSourceId);

                if (! $creditMemoMapping || ! $invoiceMapping) {
                    $skipped++;
                    $diagnostics[] = [
                        'quickbooks_payment_id' => $paymentId,
                        'quickbooks_credit_memo_id' => $creditMemoSourceId,
                        'quickbooks_invoice_id' => $invoiceSourceId,
                        'reason' => ! $creditMemoMapping ? 'credit_memo_mapping_not_found' : 'invoice_mapping_not_found',
                    ];
                    continue;
                }

                if ($creditMemoMapping->local_type !== self::CREDIT_MEMO_TYPE || $invoiceMapping->local_type !== self::INVOICE_TYPE) {
                    $skipped++;
                    $diagnostics[] = [
                        'quickbooks_payment_id' => $paymentId,
                        'reason' => 'mapping_destination_type_mismatch',
                    ];
                    continue;
                }

                $creditMemo = CreditMemo::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($creditMemoMapping->local_id)
                    ->lockForUpdate()
                    ->first();
                $invoice = Invoice::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($invoiceMapping->local_id)
                    ->lockForUpdate()
                    ->first();

                if (! $creditMemo || ! $invoice) {
                    $skipped++;
                    $diagnostics[] = [
                        'quickbooks_payment_id' => $paymentId,
                        'reason' => ! $creditMemo ? 'mapped_local_credit_memo_missing' : 'mapped_local_invoice_missing',
                    ];
                    continue;
                }

                if ($creditMemo->customer_id === null || (int) $creditMemo->customer_id !== (int) $invoice->customer_id) {
                    $skipped++;
                    $diagnostics[] = [
                        'quickbooks_payment_id' => $paymentId,
                        'reason' => 'invoice_credit_memo_customer_mismatch',
                    ];
                    continue;
                }

                if ($amount <= 0 || $amount > (float) $creditMemo->total + 0.009) {
                    $skipped++;
                    $diagnostics[] = [
                        'quickbooks_payment_id' => $paymentId,
                        'reason' => 'application_amount_outside_credit_memo_total',
                    ];
                    continue;
                }

                $existing = CreditMemoApplication::query()
                    ->where('tenant_id', $tenantId)
                    ->where('credit_memo_id', $creditMemo->id)
                    ->where('invoice_id', $invoice->id)
                    ->first();

                if ($existing) {
                    // The schema is one aggregate row per credit memo/invoice pair.
                    // Never add the same source Payment twice on a rerun. If another
                    // distinct source Payment points to this pair, flag it for review
                    // rather than silently overwriting or double-counting it.
                    $existingSourceIds = data_get($existing->source_data, 'source_payment_ids', []);
                    if (is_array($existingSourceIds) && in_array($paymentId, array_map('strval', $existingSourceIds), true)) {
                        $alreadyApplied++;
                    } else {
                        $skipped++;
                        $diagnostics[] = [
                            'quickbooks_payment_id' => $paymentId,
                            'reason' => 'application_pair_already_has_a_different_source_payment',
                        ];
                    }
                    continue;
                }

                // Do not apply more than the credit memo's remaining local amount.
                $creditAlreadyApplied = (float) CreditMemoApplication::query()
                    ->where('tenant_id', $tenantId)
                    ->where('credit_memo_id', $creditMemo->id)
                    ->sum('amount_applied');
                if ($creditAlreadyApplied + $amount > (float) $creditMemo->total + 0.009) {
                    $skipped++;
                    $diagnostics[] = [
                        'quickbooks_payment_id' => $paymentId,
                        'reason' => 'credit_memo_remaining_amount_exceeded',
                    ];
                    continue;
                }

                // Calculate invoice capacity without mutating invoice aggregates.
                $cashPaid = $this->netCashPayments($tenantId, (int) $invoice->id);
                $creditAlreadyAppliedToInvoice = (float) CreditMemoApplication::query()
                    ->where('tenant_id', $tenantId)
                    ->where('invoice_id', $invoice->id)
                    ->sum('amount_applied');
                $invoiceRemaining = max(0, (float) $invoice->total - $cashPaid - $creditAlreadyAppliedToInvoice);
                if ($amount > $invoiceRemaining + 0.009) {
                    $skipped++;
                    $diagnostics[] = [
                        'quickbooks_payment_id' => $paymentId,
                        'reason' => $pair['reason'],
                        'invoice_id' => $pair['invoice_id'] ?? null,
                        'credit_memo_id' => $pair['credit_memo_id'] ?? null,
                        'invoice_amount' => $pair['invoice_amount'] ?? null,
                        'credit_memo_amount' => $pair['credit_memo_amount'] ?? null,
                    ];
                    continue;
                }

                CreditMemoApplication::query()->create([
                    'tenant_id' => $tenantId,
                    'credit_memo_id' => $creditMemo->id,
                    'invoice_id' => $invoice->id,
                    'amount_applied' => round($amount, 2),
                    'applied_at' => $this->validDateTime($payment['TxnDate'] ?? null),
                    // Keep the QBO Payment ID as the source event proving the application.
                    'quickbooks_linked_txn_id' => $paymentId,
                    'source_data' => [
                        'source_payment_ids' => [$paymentId],
                        'quickbooks_credit_memo_id' => $creditMemoSourceId,
                        'quickbooks_invoice_id' => $invoiceSourceId,
                        'source_total_amount' => $this->numericOrNull($payment['TotalAmt'] ?? null),
                        'source_customer_id' => $this->nullableString(data_get($payment, 'CustomerRef.value')),
                        'source_payment' => $payment,
                    ],
                ]);

                $created++;
            }

            if ($diagnostics !== []) {
                Log::warning('QuickBooks credit memo application reconciliation skipped source records.', [
                    'connection_id' => $connection->id,
                    'tenant_id' => $tenantId,
                    'skipped_count' => $skipped,
                    'samples' => array_slice($diagnostics, 0, 50),
                ]);
            }

            return [
                'connection_id' => $connection->id,
                'entity_type' => 'credit_memo_applications',
                'fetched_payment_count' => count($payments),
                'created_count' => $created,
                'already_applied_count' => $alreadyApplied,
                'skipped_count' => $skipped,
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

            if (! is_array($rows) || ($rows !== [] && ! array_is_list($rows))) {
                throw new QuickBooksApiException('QuickBooks returned an unexpected payment list for credit reconciliation.', 502);
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new QuickBooksApiException('QuickBooks returned an unexpected payment list for credit reconciliation.', 502);
                }
            }

            $records = array_merge($records, array_values($rows));
            if (count($rows) < self::PAGE_SIZE) {
                return $records;
            }
            $startPosition += count($rows);
        }

        throw new QuickBooksApiException(
            'The QuickBooks payment list exceeds the current reconciliation safety limit. Please contact support.',
            413,
        );
    }

    /**
     * Only accepts the unambiguous common QBO representation: exactly one invoice
     * and one credit memo in the payment, each with an explicit positive line amount,
     * and those amounts match. We do not split one line across multiple transactions.
     *
     * @return array<string, mixed>
     */
    private function extractSingleCreditApplication(array $payment): array
    {
        // Only zero-total Payment records are considered here. A non-zero Payment
        // can represent cash received and must remain in the cash-payment workflow.
        $totalAmount = $this->numericOrNull($payment['TotalAmt'] ?? null);
        if ($totalAmount === null || abs($totalAmount) > 0.009) {
            return ['status' => 'not_applicable'];
        }

        $lines = $this->normaliseLines($payment['Line'] ?? []);
        if ($lines === null) {
            return ['status' => 'invalid', 'reason' => 'invalid_payment_line_structure'];
        }

        $invoiceAmounts = [];
        $creditMemoAmounts = [];

        foreach ($lines as $line) {
            $linked = $this->normaliseLines($line['LinkedTxn'] ?? []);
            if ($linked === null) {
                return ['status' => 'invalid', 'reason' => 'invalid_linked_transaction_structure'];
            }
            if ($linked === []) {
                continue;
            }

            $types = array_values(array_filter($linked, fn ($txn) => in_array($txn['TxnType'] ?? null, ['Invoice', 'CreditMemo'], true)));
            if ($types === []) {
                continue;
            }
            if (count($linked) !== 1 || count($types) !== 1) {
                return ['status' => 'invalid', 'reason' => 'ambiguous_multiple_transactions_on_payment_line'];
            }

            $txn = $types[0];
            $sourceId = $this->nullableString($txn['TxnId'] ?? null);
            $amount = $this->numericOrNull($line['Amount'] ?? null);
            if ($sourceId === null || $amount === null || $amount <= 0) {
                return ['status' => 'invalid', 'reason' => 'missing_link_id_or_positive_line_amount'];
            }

            if (($txn['TxnType'] ?? null) === 'Invoice') {
                $invoiceAmounts[$sourceId] = ($invoiceAmounts[$sourceId] ?? 0.0) + $amount;
            } else {
                $creditMemoAmounts[$sourceId] = ($creditMemoAmounts[$sourceId] ?? 0.0) + $amount;
            }
        }

        if ($invoiceAmounts === [] || $creditMemoAmounts === []) {
            return ['status' => 'not_applicable'];
        }
        if (count($invoiceAmounts) !== 1 || count($creditMemoAmounts) !== 1) {
            return ['status' => 'invalid', 'reason' => 'payment_links_multiple_invoices_or_credit_memos'];
        }

        $invoiceId = (string) array_key_first($invoiceAmounts);
        $creditMemoId = (string) array_key_first($creditMemoAmounts);
        $invoiceAmount = round((float) $invoiceAmounts[$invoiceId], 2);
        $creditMemoAmount = round((float) $creditMemoAmounts[$creditMemoId], 2);

        if (abs($invoiceAmount - $creditMemoAmount) > 0.009) {
            return [
                'status' => 'invalid',
                'reason' => 'invoice_and_credit_memo_link_amounts_do_not_match',
                'invoice_amount' => $invoiceAmount,
                'credit_memo_amount' => $creditMemoAmount,
                'invoice_id' => $invoiceId,
                'credit_memo_id' => $creditMemoId,
            ];
        }

        return [
            'status' => 'candidate',
            'invoice_id' => $invoiceId,
            'credit_memo_id' => $creditMemoId,
            'amount' => $invoiceAmount,
        ];
    }


    private function netCashPayments(int $tenantId, int $invoiceId): float
    {
        if (! Schema::hasTable('invoice_payments')) {
            return 0.0;
        }

        $query = DB::table('invoice_payments as ip')
            ->where('ip.tenant_id', $tenantId)
            ->where('ip.invoice_id', $invoiceId);

        if (Schema::hasColumn('invoice_payments', 'deleted_at')) {
            $query->whereNull('ip.deleted_at');
        }

        if (Schema::hasTable('invoice_payment_reversals')) {
            $query->leftJoin('invoice_payment_reversals as ipr', 'ipr.invoice_payment_id', '=', 'ip.id');
            $net = $query->selectRaw('COALESCE(SUM(ip.amount - COALESCE(ipr.amount, 0)), 0) as net_amount')->value('net_amount');
            return max(0.0, (float) $net);
        }

        return max(0.0, (float) $query->sum('ip.amount'));
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
        return [$lines];
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

    private function validDateTime(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year) ? $value.' 00:00:00' : null;
    }
}
