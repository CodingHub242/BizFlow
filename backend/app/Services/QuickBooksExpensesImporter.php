<?php

namespace App\Services;

use App\ExpensePaymentMethod;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QuickBooksExpensesImporter
{
    private const ENTITY_TYPE = 'expenses';
    private const LOCAL_TYPE = Expense::class;
    private const PAGE_SIZE = 1000;
    private const MAX_PAGES = 50;

    public function __construct(
        private QuickBooksAccountingApiService $quickBooks,
    ) {}

    public function import(
        QuickBooksConnection $connection,
        int $tenantId,
        int $branchId,
        int $userId,
    ): array {
        if ((int) $connection->tenant_id !== $tenantId) {
            throw new RuntimeException(
                'The QuickBooks connection does not belong to this tenant.'
            );
        }

        if ($connection->connection_status !== 'connected') {
            throw new QuickBooksApiException(
                'This QuickBooks connection is not active.',
                409,
            );
        }

        $branch = Branch::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($branchId)
            ->first();

        if (! $branch) {
            throw new QuickBooksApiException(
                'Select a branch that belongs to this Bizflow tenant.',
                422,
            );
        }

        // Fetch provider records before opening the local transaction.
        $records = $this->fetchAllPurchases($connection);

        return DB::transaction(function () use (
            $connection,
            $tenantId,
            $branch,
            $userId,
            $records,
        ): array {
            $imported = 0;
            $alreadyImported = 0;
            $skipped = 0;
            $skippedDiagnostics = [];
            $unresolvedCategoryReferences = 0;

            foreach ($records as $record) {
                $quickBooksId = $this->nullableString(
                    $record['Id'] ?? null
                );

                $date = $this->validDate($record['TxnDate'] ?? null);
                $amount = $this->numericOrNull(
                    $record['TotalAmt'] ?? null
                );

                if (
                    $quickBooksId === null
                    || $date === null
                    || $amount === null
                    || $amount <= 0
                ) {
                    $skipped++;

                    $issues = array_values(array_filter([
                        $quickBooksId === null ? 'Id' : null,
                        $date === null ? 'TxnDate' : null,
                        $amount === null ? 'TotalAmt' : null,
                        $amount !== null && $amount <= 0
                            ? 'TotalAmt_must_be_positive'
                            : null,
                    ]));

                    $skippedDiagnostics[] = [
                        'quickbooks_id' => $quickBooksId,
                        'reason' => 'missing_or_invalid_required_fields',
                        'field_issues' => $issues,
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
                    throw new RuntimeException(
                        'An expense source mapping has an unexpected destination type.'
                    );
                }

                if ($mapping) {
                    $existingExpense = Expense::withTrashed()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($mapping->local_id)
                        ->first();

                    if ($existingExpense) {
                        // Never overwrite local edits on repeat imports.
                        $alreadyImported++;
                        continue;
                    }
                }

                $paymentMethod = $this->normalisePaymentMethod(
                    $record['PaymentType'] ?? null,
                    data_get($record, 'CreditCardPayment.CreditCardAccountRef.name'),
                    data_get($record, 'CheckPayment.BankAccountRef.name'),
                    data_get($record, 'CashPayment.BankAccountRef.name'),
                );

                $lines = $this->normaliseLines(
                    data_get($record, 'Line', [])
                );

                $lineDescriptions = [];
                $sourceCategoryReferences = [];

                foreach ($lines as $line) {
                    $description = $this->nullableString(
                        $line['Description'] ?? null
                    );

                    if ($description !== null) {
                        $lineDescriptions[] = $description;
                    }

                    $accountRef = $this->nullableString(
                        data_get(
                            $line,
                            'AccountBasedExpenseLineDetail.AccountRef.value'
                        )
                    );

                    if ($accountRef !== null) {
                        $sourceCategoryReferences[] = [
                            'quickbooks_account_id' => $accountRef,
                            'quickbooks_account_name' =>
                                $this->nullableString(
                                    data_get(
                                        $line,
                                        'AccountBasedExpenseLineDetail.AccountRef.name'
                                    )
                                ),
                        ];
                    }
                }

                $sourceCategoryReferences = array_values(
                    array_unique(
                        $sourceCategoryReferences,
                        SORT_REGULAR,
                    )
                );

                // Bizflow Category and QuickBooks Chart of Accounts are
                // different concepts. Do not assign category_id from an
                // account reference without an explicit category mapping.
                if ($sourceCategoryReferences !== []) {
                    $unresolvedCategoryReferences++;
                }

                $description = $this->nullableString(
                    $record['PrivateNote'] ?? null
                );

                if ($description === null && $lineDescriptions !== []) {
                    $description = implode(
                        '; ',
                        array_slice(array_unique($lineDescriptions), 0, 5)
                    );
                }

                $documentNumber = $this->nullableString(
                    $record['DocNumber'] ?? null
                );

                $description ??= $documentNumber !== null
                    ? 'QuickBooks purchase ' . $documentNumber
                    : 'QuickBooks purchase ' . $quickBooksId;

                $reference = $documentNumber
                    ?? $this->nullableString($record['PaymentRefNum'] ?? null);

                $notes = $this->buildNotes(
                    $record,
                    $sourceCategoryReferences,
                );

                $expense = Expense::query()->create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branch->id,
                    'category_id' => null,
                    'created_by' => $userId,
                    'amount' => number_format($amount, 2, '.', ''),
                    'payment_method' => $paymentMethod->value,
                    'reference' => $reference,
                    'expense_date' => $date,
                    'description' => $description,
                    'notes' => $notes,
                ]);

                $payloadHash = hash(
                    'sha256',
                    json_encode($record, JSON_THROW_ON_ERROR)
                );

                $mappingData = [
                    'tenant_id' => $tenantId,
                    'quickbooks_connection_id' => $connection->id,
                    'entity_type' => self::ENTITY_TYPE,
                    'quickbooks_id' => $quickBooksId,
                    'local_type' => self::LOCAL_TYPE,
                    'local_id' => $expense->id,
                    'payload_hash' => $payloadHash,
                    'imported_at' => now(),
                    'metadata' => [
                        'source_doc_number' => $documentNumber,
                        'source_payment_type' =>
                            $this->nullableString($record['PaymentType'] ?? null),
                        'source_payment_reference' =>
                            $this->nullableString($record['PaymentRefNum'] ?? null),
                        'source_entity_ref' => data_get($record, 'EntityRef'),
                        'source_account_references' => $sourceCategoryReferences,
                        'source_line_count' => count($lines),
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
                'unresolved_category_reference_count' =>
                    $unresolvedCategoryReferences,
                'skipped_diagnostics' => $skippedDiagnostics,
            ];
        }, 3);
    }

    private function fetchAllPurchases(
        QuickBooksConnection $connection,
    ): array {
        $records = [];
        $startPosition = 1;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->quickBooks->query(
                $connection,
                'select * from Purchase startposition '
                    . $startPosition
                    . ' maxresults '
                    . self::PAGE_SIZE,
            );

            $rows = $response['Purchase'] ?? [];

            if (! is_array($rows)) {
                throw new QuickBooksApiException(
                    'QuickBooks returned an unexpected purchase list.',
                    502,
                );
            }

            $rows = array_values(array_filter($rows, 'is_array'));
            $records = array_merge($records, $rows);

            if (count($rows) < self::PAGE_SIZE) {
                return $records;
            }

            $startPosition += count($rows);
        }

        throw new QuickBooksApiException(
            'The QuickBooks purchase list exceeds the current import safety limit.',
            413,
        );
    }

    private function sourceMapping(
        int $tenantId,
        int $connectionId,
        string $entityType,
        string $sourceId,
    ): ?QuickBooksImportMapping {
        return QuickBooksImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('quickbooks_connection_id', $connectionId)
            ->where('entity_type', $entityType)
            ->where('quickbooks_id', $sourceId)
            ->first();
    }

    private function normalisePaymentMethod(
        mixed $paymentType,
        mixed $creditCardAccountName,
        mixed $checkBankAccountName,
        mixed $cashBankAccountName,
    ): ExpensePaymentMethod {
        $type = strtolower(trim((string) $paymentType));

        return match ($type) {
            'cash' => ExpensePaymentMethod::CASH,
            'check', 'cheque', 'bank transfer', 'bank_transfer' =>
                ExpensePaymentMethod::BANK_TRANSFER,
            'creditcard', 'credit card', 'card' =>
                ExpensePaymentMethod::CARD,
            default => $this->inferPaymentMethodFromAccountNames(
                $creditCardAccountName,
                $checkBankAccountName,
                $cashBankAccountName,
            ),
        };
    }

    private function inferPaymentMethodFromAccountNames(
        mixed $creditCardAccountName,
        mixed $checkBankAccountName,
        mixed $cashBankAccountName,
    ): ExpensePaymentMethod {
        if ($this->nullableString($creditCardAccountName) !== null) {
            return ExpensePaymentMethod::CARD;
        }

        if (
            $this->nullableString($checkBankAccountName) !== null
            || $this->nullableString($cashBankAccountName) !== null
        ) {
            return ExpensePaymentMethod::BANK_TRANSFER;
        }

        return ExpensePaymentMethod::OTHER;
    }

    private function normaliseLines(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }

        if ($lines === []) {
            return [];
        }

        if (array_is_list($lines)) {
            return array_values(array_filter($lines, 'is_array'));
        }

        // Defensively support a single Line object.
        return isset($lines['Amount']) || isset($lines['DetailType'])
            ? [$lines]
            : array_values(array_filter($lines, 'is_array'));
    }

    private function buildNotes(
        array $record,
        array $sourceCategoryReferences,
    ): ?string {
        $parts = [];

        $memo = $this->nullableString($record['PrivateNote'] ?? null);

        if ($memo !== null) {
            $parts[] = 'QuickBooks note: ' . $memo;
        }

        if ($sourceCategoryReferences !== []) {
            $parts[] = 'Unmapped QuickBooks account references: '
                . json_encode(
                    $sourceCategoryReferences,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
        }

        $entityName = $this->nullableString(
            data_get($record, 'EntityRef.name')
        );

        if ($entityName !== null) {
            $parts[] = 'QuickBooks payee: ' . $entityName;
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value
            ? $value
            : null;
    }

    private function numericOrNull(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
