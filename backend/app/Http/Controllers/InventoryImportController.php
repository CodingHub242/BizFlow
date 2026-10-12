<?php

namespace App\Http\Controllers;

use App\Imports\InventorySpreadsheetRowsImport;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Inventory;
use App\Models\InventoryImportBatch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Throwable;

class InventoryImportController extends Controller
{
    private const MAX_ROWS = 5000;

    private const REQUIRED_HEADERS = [
        'branch_code',
        'sku',
        'quantity',
    ];

    private const OPTIONAL_HEADERS = [
        'reorder_level',
    ];

    /**
     * Upload, analyze and preview an inventory file.
     *
     * This endpoint does not change inventory quantities.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:csv,txt,xlsx',
                'extensions:csv,xlsx',
            ],
        ]);

        $file = $validated['file'];
        $tenantId = (int) $request->user()->tenant_id;
        $userId = (int) $request->user()->id;
        $extension = strtolower($file->getClientOriginalExtension());

        $uuid = (string) Str::uuid();

        $path = $file->storeAs(
            "inventory-imports/tenant-{$tenantId}",
            "{$uuid}.{$extension}",
            'local'
        );

        if (! is_string($path)) {
            return response()->json([
                'success' => false,
                'message' => 'The uploaded file could not be stored.',
            ], 500);
        }

        try {
            $absolutePath = Storage::disk('local')->path($path);

            /*
             * Inspect the header row before attempting to analyze data.
             */
            $headingSheets = (new HeadingRowImport())->toArray(
                $absolutePath
            );

            $firstSheet = $headingSheets[0] ?? [];

            $rawHeaders = isset($firstSheet[0])
                && is_array($firstSheet[0])
                    ? $firstSheet[0]
                    : $firstSheet;

            if (! is_array($rawHeaders) || $rawHeaders === []) {
                return $this->rejectFile(
                    $path,
                    'The spreadsheet is empty or has no header row.'
                );
            }

            $headers = [];

            foreach ($rawHeaders as $header) {
                $normalized = Str::slug(
                    trim((string) $header),
                    '_'
                );

                if ($normalized !== '') {
                    $headers[] = $normalized;
                }
            }

            $duplicates = array_keys(
                array_filter(
                    array_count_values($headers),
                    static fn (int $count): bool => $count > 1
                )
            );

            if ($duplicates !== []) {
                return $this->rejectFile(
                    $path,
                    'The file contains duplicate column headers.',
                    ['duplicate_headers' => $duplicates]
                );
            }

            $missingHeaders = array_values(
                array_diff(self::REQUIRED_HEADERS, $headers)
            );

            if ($missingHeaders !== []) {
                return $this->rejectFile(
                    $path,
                    'The file is missing required columns.',
                    ['missing_headers' => $missingHeaders]
                );
            }

            /*
             * Read data without saving inventory or movement records.
             */
            $reader = new InventorySpreadsheetRowsImport();

            Excel::import($reader, $absolutePath);

            if ($reader->rows->count() > self::MAX_ROWS) {
                return $this->rejectFile(
                    $path,
                    'The file contains too many rows. The maximum is '
                        . self::MAX_ROWS . ' rows.'
                );
            }

            $inputRows = [];

            foreach ($reader->rows as $index => $row) {
                $cells = $row instanceof Collection
                    ? $row->toArray()
                    : (array) $row;

                if ($this->isBlankRow($cells)) {
                    continue;
                }

                $inputRows[] = [
                    'row_number' => (int) $index + 2,
                    'cells' => $cells,
                ];
            }

            if ($inputRows === []) {
                return $this->rejectFile(
                    $path,
                    'No data rows were found below the header row.'
                );
            }

            $previewRows = $this->buildPreviewRows(
                $tenantId,
                $inputRows
            );

            $validRows = count(array_filter(
                $previewRows,
                static fn (array $row): bool => $row['valid']
            ));

            $invalidRows = count($previewRows) - $validRows;

            $batch = InventoryImportBatch::create([
                'uuid' => $uuid,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $extension,
                'status' => 'previewed',
                'row_count' => count($previewRows),
                'valid_rows' => $validRows,
                'invalid_rows' => $invalidRows,
                'preview_rows' => $previewRows,
                'expires_at' => now()->addMinutes(30),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'File analyzed. No stock quantities were changed.',
                'data' => $this->formatBatch($batch),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The file could not be read. Please check that it is a valid CSV or XLSX spreadsheet.',
            ], 422);
        }
    }

    /**
     * Retrieve a previously generated preview.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $batch = InventoryImportBatch::query()
            ->where('uuid', $uuid)
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (
            $batch->status === 'previewed'
            && $batch->expires_at?->isPast()
        ) {
            Storage::disk('local')->delete($batch->file_path);

            $batch->update([
                'status' => 'expired',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'This import preview has expired. Please upload the file again.',
            ], 410);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatBatch($batch),
        ]);
    }

    /**
     * Validate rows against this tenant's branches and catalog items.
     */
    private function buildPreviewRows(
        int $tenantId,
        array $inputRows
    ): array {
        $prepared = [];
        $branchCodes = [];
        $skus = [];
        $pairCounts = [];

        foreach ($inputRows as $input) {
            $cells = $input['cells'];

            $branchCode = strtoupper(
                $this->cellText($cells['branch_code'] ?? null)
            );

            $sku = strtoupper(
                $this->cellText($cells['sku'] ?? null)
            );

            $rawQuantity = $cells['quantity'] ?? null;
            $rawReorder = $cells['reorder_level'] ?? null;

            $reorderProvided = ! (
                $rawReorder === null
                || (
                    is_string($rawReorder)
                    && trim($rawReorder) === ''
                )
            );

            $quantity = $this->parseDecimal($rawQuantity);
            $reorderLevel = $reorderProvided
                ? $this->parseDecimal($rawReorder)
                : null;

            $errors = [];

            if ($branchCode === '') {
                $errors[] = 'Branch code is required.';
            } elseif (mb_strlen($branchCode) > 255) {
                $errors[] = 'Branch code cannot exceed 255 characters.';
            }

            if ($sku === '') {
                $errors[] = 'SKU is required.';
            } elseif (mb_strlen($sku) > 255) {
                $errors[] = 'SKU cannot exceed 255 characters.';
            }

            if ($quantity === null) {
                $errors[] = 'Quantity must be zero or greater, with at most three decimal places.';
            }

            if ($reorderProvided && $reorderLevel === null) {
                $errors[] = 'Reorder level must be zero or greater, with at most three decimal places.';
            }

            if ($branchCode !== '' && mb_strlen($branchCode) <= 255) {
                $branchCodes[] = strtolower($branchCode);
            }

            if ($sku !== '' && mb_strlen($sku) <= 255) {
                $skus[] = strtolower($sku);
            }

            if ($branchCode !== '' && $sku !== '') {
                $pair = strtolower($branchCode . '|' . $sku);

                $pairCounts[$pair] = ($pairCounts[$pair] ?? 0) + 1;
            }

            $prepared[] = [
                'row_number' => $input['row_number'],
                'branch_code' => $branchCode,
                'sku' => $sku,
                'quantity' => $quantity,
                'reorder_level' => $reorderLevel,
                'reorder_provided' => $reorderProvided,
                'errors' => $errors,
            ];
        }

        $branchesByCode = [];
        $itemsBySku = [];

        $branchCodes = array_values(array_unique($branchCodes));
        $skus = array_values(array_unique($skus));

        if ($branchCodes !== []) {
            $branches = Branch::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('code')
                ->whereIn(DB::raw('LOWER(code)'), $branchCodes)
                ->get(['id', 'name', 'code']);

            foreach ($branches as $branch) {
                $key = strtolower(trim((string) $branch->code));

                $branchesByCode[$key][] = $branch;
            }
        }

        if ($skus !== []) {
            $items = CatalogItem::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('sku')
                ->whereIn(DB::raw('LOWER(sku)'), $skus)
                ->get([
                    'id',
                    'name',
                    'sku',
                    'type',
                    'track_inventory',
                ]);

            foreach ($items as $item) {
                $key = strtolower(trim((string) $item->sku));

                $itemsBySku[$key][] = $item;
            }
        }

        /*
         * Fetch existing records in batches to avoid one query per row.
         */
        $branchIds = [];
        $itemIds = [];

        foreach ($prepared as $row) {
            $branchMatches = $branchesByCode[
                strtolower($row['branch_code'])
            ] ?? [];

            $itemMatches = $itemsBySku[
                strtolower($row['sku'])
            ] ?? [];

            if (count($branchMatches) === 1) {
                $branchIds[] = $branchMatches[0]->id;
            }

            if (count($itemMatches) === 1) {
                $itemIds[] = $itemMatches[0]->id;
            }
        }

        $branchIds = array_values(array_unique($branchIds));
        $itemIds = array_values(array_unique($itemIds));

        $inventoryByPair = [];

        if ($branchIds !== [] && $itemIds !== []) {
            $existingRecords = Inventory::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('branch_id', $branchIds)
                ->whereIn('catalog_item_id', $itemIds)
                ->get();

            foreach ($existingRecords as $record) {
                $key = $record->branch_id . ':' . $record->catalog_item_id;

                $inventoryByPair[$key] = $record;
            }
        }

        $preview = [];

        foreach ($prepared as $row) {
            $errors = $row['errors'];

            $branchKey = strtolower($row['branch_code']);
            $skuKey = strtolower($row['sku']);

            $branchMatches = $branchesByCode[$branchKey] ?? [];
            $itemMatches = $itemsBySku[$skuKey] ?? [];

            $branch = count($branchMatches) === 1
                ? $branchMatches[0]
                : null;

            $item = count($itemMatches) === 1
                ? $itemMatches[0]
                : null;

            if ($row['branch_code'] !== '') {
                if (count($branchMatches) === 0) {
                    $errors[] = 'Branch code was not found in your company.';
                } elseif (count($branchMatches) > 1) {
                    $errors[] = 'Branch code is ambiguous. Ensure branch codes are unique.';
                }
            }

            if ($row['sku'] !== '') {
                if (count($itemMatches) === 0) {
                    $errors[] = 'SKU was not found in your company catalog.';
                } elseif (count($itemMatches) > 1) {
                    $errors[] = 'SKU is ambiguous. Ensure SKUs are unique.';
                }
            }

            if ($item !== null && (
                $item->type !== 'product'
                || ! $item->track_inventory
            )) {
                $errors[] = 'This item does not track physical inventory.';
            }

            if ($row['branch_code'] !== '' && $row['sku'] !== '') {
                $pair = strtolower(
                    $row['branch_code'] . '|' . $row['sku']
                );

                if (($pairCounts[$pair] ?? 0) > 1) {
                    $errors[] = 'This branch and SKU combination appears more than once in the file.';
                }
            }

            $errors = array_values(array_unique($errors));

            $existing = null;

            if ($branch !== null && $item !== null) {
                $key = $branch->id . ':' . $item->id;

                $existing = $inventoryByPair[$key] ?? null;
            }

            $currentQuantity = $existing !== null
                ? (float) $existing->quantity
                : 0.0;

            $currentReorder = $existing !== null
                ? (float) $existing->reorder_level
                : 0.0;

            $targetQuantity = $row['quantity'];

            $targetReorder = $row['reorder_provided']
                ? $row['reorder_level']
                : ($existing !== null ? $currentReorder : 0.0);

            $valid = $errors === [];
            $action = 'invalid';
            $quantityDelta = null;

            if (
                $valid
                && $targetQuantity !== null
                && $targetReorder !== null
            ) {
                $quantityDelta = round(
                    $targetQuantity - $currentQuantity,
                    3
                );

                if ($existing === null) {
                    $action = 'create';
                } elseif (
                    abs($quantityDelta) > 0.000001
                    || abs($targetReorder - $currentReorder) > 0.000001
                ) {
                    $action = 'update';
                } else {
                    $action = 'no_change';
                }
            }

            $preview[] = [
                'row_number' => $row['row_number'],
                'branch_code' => $row['branch_code'],
                'branch_name' => $branch?->name,
                'sku' => $row['sku'],
                'catalog_item_name' => $item?->name,
                'current_quantity' => $branch !== null && $item !== null
                    ? $currentQuantity
                    : null,
                'target_quantity' => $targetQuantity,
                'quantity_delta' => $quantityDelta,
                'current_reorder_level' => $branch !== null && $item !== null
                    ? $currentReorder
                    : null,
                'target_reorder_level' => $targetReorder,
                'action' => $action,
                'valid' => $valid,
                'errors' => $errors,
            ];
        }

        return $preview;
    }

    private function formatBatch(
        InventoryImportBatch $batch
    ): array {
        return [
            'import_id' => $batch->uuid,
            'file_name' => $batch->file_name,
            'file_type' => $batch->file_type,
            'status' => $batch->status,
            'expires_at' => $batch->expires_at?->toIso8601String(),
            'summary' => [
                'total_rows' => $batch->row_count,
                'valid_rows' => $batch->valid_rows,
                'invalid_rows' => $batch->invalid_rows,
                'can_confirm' => $batch->status === 'previewed'
                    && $batch->valid_rows > 0,
            ],
            'required_headers' => self::REQUIRED_HEADERS,
            'optional_headers' => self::OPTIONAL_HEADERS,
            'rows' => $batch->preview_rows ?? [],
        ];
    }

    private function rejectFile(
        string $path,
        string $message,
        array $errors = []
    ): JsonResponse {
        Storage::disk('local')->delete($path);

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], 422);
    }

    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function cellText(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Accept non-negative numbers with at most three decimal places.
     */
    private function parseDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if (
            ! is_finite($number)
            || $number < 0
            || $number > 999999999999.999
            || abs(($number * 1000) - round($number * 1000)) > 0.000001
        ) {
            return null;
        }

        return round($number, 3);
    }
}