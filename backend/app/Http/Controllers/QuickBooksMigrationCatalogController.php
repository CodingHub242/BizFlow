<?php

namespace App\Http\Controllers;

use App\Models\QuickBooksConnection;
use App\Services\QuickBooksAccountingApiService;
use App\Services\QuickBooksApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class QuickBooksMigrationCatalogController extends Controller
{
    /**
     * This allow-list intentionally contains only QuickBooks entities that Bizflow
     * plans to migrate. Entity names are never accepted directly from the request.
     */
    private const ENTITIES = [
        'customers' => ['label' => 'Customers', 'qbo_entity' => 'Customer', 'response_key' => 'Customer'],
        'suppliers' => ['label' => 'Suppliers', 'qbo_entity' => 'Vendor', 'response_key' => 'Vendor'],
        'catalog_items' => ['label' => 'Products and services', 'qbo_entity' => 'Item', 'response_key' => 'Item'],
        'invoices' => ['label' => 'Invoices', 'qbo_entity' => 'Invoice', 'response_key' => 'Invoice'],
        'bills' => ['label' => 'Bills', 'qbo_entity' => 'Bill', 'response_key' => 'Bill'],
        'estimates' => ['label' => 'Estimates', 'qbo_entity' => 'Estimate', 'response_key' => 'Estimate'],
        'chart_of_accounts' => ['label' => 'Chart of Accounts', 'qbo_entity' => 'Account', 'response_key' => 'Account'],
        'expenses' => ['label' => 'Expenses', 'qbo_entity' => 'Purchase', 'response_key' => 'Purchase'],
        'payments' => ['label' => 'Customer payments', 'qbo_entity' => 'Payment', 'response_key' => 'Payment'],
    ];

    /** Return record counts for all supported entity types. */
    public function index(
        Request $request,
        QuickBooksConnection $connection,
        QuickBooksAccountingApiService $quickBooks,
    ): JsonResponse {
        $this->assertTenantOwnsConnection($request, $connection);

        try {
            $entities = [];

            foreach (self::ENTITIES as $entityType => $spec) {
                $queryResponse = $quickBooks->query(
                    $connection,
                    'select count(*) from '.$spec['qbo_entity'],
                );

                if (! array_key_exists('totalCount', $queryResponse)) {
                    throw new QuickBooksApiException(
                        'QuickBooks returned an unexpected count response.',
                        502,
                    );
                }

                $entities[] = [
                    'entity_type' => $entityType,
                    'label' => $spec['label'],
                    'quickbooks_entity' => $spec['qbo_entity'],
                    'record_count' => (int) $queryResponse['totalCount'],
                    'sample_url' => url('/api/integrations/quickbooks/connections/'.$connection->id.'/migration-catalog/'.$entityType),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'connection_id' => $connection->id,
                    'realm_id' => $connection->realm_id,
                    'entities' => $entities,
                ],
            ]);
        } catch (QuickBooksApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        } catch (Throwable $exception) {
            Log::warning('QuickBooks migration catalog count request failed.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to discover QuickBooks records right now.',
            ], 502);
        }
    }

    /** Return a small, normalized sample for one allow-listed entity type. */
    public function show(
        Request $request,
        QuickBooksConnection $connection,
        string $entityType,
        QuickBooksAccountingApiService $quickBooks,
    ): JsonResponse {
        $this->assertTenantOwnsConnection($request, $connection);

        $spec = self::ENTITIES[$entityType] ?? null;
        abort_if($spec === null, 404, 'Unsupported QuickBooks migration entity.');

        $sampleSize = min(max((int) $request->query('sample_size', 3), 1), 5);

        try {
            $countResponse = $quickBooks->query(
                $connection,
                'select count(*) from '.$spec['qbo_entity'],
            );

            if (! array_key_exists('totalCount', $countResponse)) {
                throw new QuickBooksApiException(
                    'QuickBooks returned an unexpected count response.',
                    502,
                );
            }

            $sampleResponse = $quickBooks->query(
                $connection,
                'select * from '.$spec['qbo_entity'].' startposition 1 maxresults '.$sampleSize,
            );

            $rows = $sampleResponse[$spec['response_key']] ?? [];
            if (! is_array($rows)) {
                $rows = [];
            }

            $samples = array_values(array_map(
                fn (array $row): array => $this->normalizeSample($entityType, $row),
                array_filter($rows, 'is_array'),
            ));

            return response()->json([
                'success' => true,
                'data' => [
                    'connection_id' => $connection->id,
                    'entity_type' => $entityType,
                    'label' => $spec['label'],
                    'record_count' => (int) $countResponse['totalCount'],
                    'sample_count' => count($samples),
                    'more_records_available' => (int) $countResponse['totalCount'] > count($samples),
                    'samples' => $samples,
                ],
            ]);
        } catch (QuickBooksApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        } catch (Throwable $exception) {
            Log::warning('QuickBooks migration entity preview failed.', [
                'entity_type' => $entityType,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to preview this QuickBooks entity right now.',
            ], 502);
        }
    }

    private function assertTenantOwnsConnection(Request $request, QuickBooksConnection $connection): void
    {
        abort_unless(
            (int) $connection->tenant_id === (int) $request->user()->tenant_id,
            404,
        );
    }

    /** Keep preview payloads small; never return full QuickBooks entities. */
    private function normalizeSample(string $entityType, array $row): array
    {
        $base = ['quickbooks_id' => isset($row['Id']) ? (string) $row['Id'] : null];

        return match ($entityType) {
            'customers' => $base + [
                'name' => $row['DisplayName'] ?? $row['CompanyName'] ?? null,
                'company_name' => $row['CompanyName'] ?? null,
                'active' => $row['Active'] ?? null,
            ],
            'suppliers' => $base + [
                'name' => $row['DisplayName'] ?? $row['CompanyName'] ?? null,
                'company_name' => $row['CompanyName'] ?? null,
                'active' => $row['Active'] ?? null,
            ],
            'catalog_items' => $base + [
                'name' => $row['Name'] ?? null,
                'type' => $row['Type'] ?? null,
                'sku' => $row['Sku'] ?? null,
                'sales_price' => $row['UnitPrice'] ?? null,
                'active' => $row['Active'] ?? null,
            ],
            'invoices' => $base + [
                'number' => $row['DocNumber'] ?? null,
                'date' => $row['TxnDate'] ?? null,
                'customer' => data_get($row, 'CustomerRef.name'),
                'total' => $row['TotalAmt'] ?? null,
                'balance' => $row['Balance'] ?? null,
            ],
            'bills' => $base + [
                'number' => $row['DocNumber'] ?? null,
                'date' => $row['TxnDate'] ?? null,
                'supplier' => data_get($row, 'VendorRef.name'),
                'total' => $row['TotalAmt'] ?? null,
                'balance' => $row['Balance'] ?? null,
            ],
            'estimates' => $base + [
                'number' => $row['DocNumber'] ?? null,
                'date' => $row['TxnDate'] ?? null,
                'customer' => data_get($row, 'CustomerRef.name'),
                'total' => $row['TotalAmt'] ?? null,
                'status' => $row['TxnStatus'] ?? null,
            ],
            'chart_of_accounts' => $base + [
                'name' => $row['Name'] ?? null,
                'account_number' => $row['AcctNum'] ?? null,
                'account_type' => $row['AccountType'] ?? null,
                'detail_type' => $row['AccountSubType'] ?? null,
                'active' => $row['Active'] ?? null,
            ],
            'expenses' => $base + [
                'date' => $row['TxnDate'] ?? null,
                'payee' => data_get($row, 'EntityRef.name'),
                'payment_type' => $row['PaymentType'] ?? null,
                'total' => $row['TotalAmt'] ?? null,
            ],
            'payments' => $base + [
                'date' => $row['TxnDate'] ?? null,
                'reference' => $row['PaymentRefNum'] ?? null,
                'customer' => data_get($row, 'CustomerRef.name'),
                'total' => $row['TotalAmt'] ?? null,
            ],
            default => $base,
        };
    }
}
