<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\QuickBooksConnection;
use App\Models\QuickBooksImportMapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports QuickBooks Online Account records into Bizflow's tenant-scoped chart
 * of accounts. It only reads from QuickBooks and writes to Bizflow.
 */
class QuickBooksChartOfAccountsImporter
{
    private const ENTITY_TYPE = 'chart_of_accounts';
    private const LOCAL_TYPE = ChartOfAccount::class;
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

        // Complete provider reads before opening a DB transaction, so network
        // latency does not hold database locks.
        $records = $this->fetchAllAccounts($connection);

        return DB::transaction(function () use ($connection, $tenantId, $records): array {
            $localIdsByQuickBooksId = [];
            $imported = 0;
            $alreadyImported = 0;
            $skipped = 0;

            foreach ($records as $record) {
                $quickBooksId = isset($record['Id']) ? trim((string) $record['Id']) : '';
                $name = isset($record['Name']) ? trim((string) $record['Name']) : '';
                $accountType = isset($record['AccountType']) ? trim((string) $record['AccountType']) : '';

                // Do not invent identities or insert invalid chart-of-account rows.
                if ($quickBooksId === '' || $name === '' || $accountType === '') {
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

                $account = null;
                if ($mapping) {
                    $account = ChartOfAccount::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($mapping->local_id)
                        ->first();
                }

                if ($account) {
                    // Preserve Bizflow-side edits on repeat runs while still
                    // maintaining the source-ID and hierarchy associations.
                    $alreadyImported++;
                } else {
                    $account = ChartOfAccount::query()->create([
                        'tenant_id' => $tenantId,
                        'parent_id' => null,
                        'account_number' => $this->nullableString($record['AcctNum'] ?? null),
                        'name' => $name,
                        'account_type' => $accountType,
                        'detail_type' => $this->nullableString($record['AccountSubType'] ?? null),
                        'description' => $this->nullableString($record['Description'] ?? null),
                        'is_sub_account' => (bool) ($record['SubAccount'] ?? isset($record['ParentRef']['value'])),
                        'is_active' => (bool) ($record['Active'] ?? true),
                    ]);
                    $imported++;
                }

                $localIdsByQuickBooksId[$quickBooksId] = $account->id;
                $payloadHash = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));

                if ($mapping) {
                    $mapping->forceFill([
                        'local_type' => self::LOCAL_TYPE,
                        'local_id' => $account->id,
                        'payload_hash' => $payloadHash,
                        'imported_at' => now(),
                        'metadata' => [
                            'name' => $name,
                            'account_type' => $accountType,
                        ],
                    ])->save();
                } else {
                    QuickBooksImportMapping::query()->create([
                        'tenant_id' => $tenantId,
                        'quickbooks_connection_id' => $connection->id,
                        'entity_type' => self::ENTITY_TYPE,
                        'quickbooks_id' => $quickBooksId,
                        'local_type' => self::LOCAL_TYPE,
                        'local_id' => $account->id,
                        'payload_hash' => $payloadHash,
                        'imported_at' => now(),
                        'metadata' => [
                            'name' => $name,
                            'account_type' => $accountType,
                        ],
                    ]);
                }
            }

            // Apply parent links only after every account ID has been mapped;
            // QuickBooks does not guarantee parent accounts arrive first.
            $hierarchyLinks = 0;
            foreach ($records as $record) {
                $quickBooksId = isset($record['Id']) ? trim((string) $record['Id']) : '';
                if ($quickBooksId === '' || ! isset($localIdsByQuickBooksId[$quickBooksId])) {
                    continue;
                }

                $parentQuickBooksId = isset($record['ParentRef']['value'])
                    ? trim((string) $record['ParentRef']['value'])
                    : '';
                $parentLocalId = $parentQuickBooksId !== ''
                    ? ($localIdsByQuickBooksId[$parentQuickBooksId] ?? null)
                    : null;

                // A parent must be a different mapped account owned by this tenant.
                if ($parentLocalId !== null && $parentLocalId === $localIdsByQuickBooksId[$quickBooksId]) {
                    $parentLocalId = null;
                }

                $account = ChartOfAccount::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($localIdsByQuickBooksId[$quickBooksId])
                    ->first();

                if (! $account) {
                    continue;
                }

                $account->forceFill([
                    'parent_id' => $parentLocalId,
                    'is_sub_account' => (bool) ($record['SubAccount'] ?? ($parentLocalId !== null)),
                ])->save();

                if ($parentLocalId !== null) {
                    $hierarchyLinks++;
                }
            }

            return [
                'connection_id' => $connection->id,
                'entity_type' => self::ENTITY_TYPE,
                'fetched_count' => count($records),
                'imported_count' => $imported,
                'already_imported_count' => $alreadyImported,
                'skipped_count' => $skipped,
                'hierarchy_links' => $hierarchyLinks,
            ];
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAllAccounts(QuickBooksConnection $connection): array
    {
        $records = [];
        $startPosition = 1;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->quickBooks->query(
                $connection,
                'select * from Account startposition '.$startPosition.' maxresults '.self::PAGE_SIZE,
            );

            $rows = $response['Account'] ?? [];
            if (! is_array($rows)) {
                throw new QuickBooksApiException('QuickBooks returned an unexpected account list.', 502);
            }

            $rows = array_values(array_filter($rows, 'is_array'));
            $records = array_merge($records, $rows);

            if (count($rows) < self::PAGE_SIZE) {
                return $records;
            }

            $startPosition += count($rows);
        }

        throw new QuickBooksApiException(
            'The QuickBooks chart of accounts exceeds the current import safety limit. Please contact support.',
            413,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
