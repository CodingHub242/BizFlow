<?php

namespace App\Http\Controllers;

use App\Exports\InventoryExport;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryExportController extends Controller
{
    public function export(Request $request): BinaryFileResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $filters = $request->validate([
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
            'branch_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('branches', 'id')
                    ->where(fn ($query) => $query->where(
                        'tenant_id',
                        $tenantId
                    )),
            ],
            'search' => ['nullable', 'string', 'max:255'],
            'stock_status' => [
                'nullable',
                Rule::in(['in-stock', 'low-stock', 'out-of-stock']),
            ],
        ]);

        $query = Inventory::query()
            ->with(['branch', 'catalogItem'])
            ->where('tenant_id', $tenantId);

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);

            $query->whereHas('catalogItem', function ($catalogQuery) use ($search) {
                $catalogQuery->where(function ($nestedQuery) use ($search) {
                    $nestedQuery
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('sku', 'ilike', "%{$search}%");
                });
            });
        }

        if (($filters['stock_status'] ?? null) === 'out-of-stock') {
            $query->where('quantity', '<=', 0);
        } elseif (($filters['stock_status'] ?? null) === 'low-stock') {
            $query->where('quantity', '>', 0)
                ->whereColumn('quantity', '<=', 'reorder_level');
        } elseif (($filters['stock_status'] ?? null) === 'in-stock') {
            $query->where('quantity', '>', 0)
                ->whereColumn('quantity', '>', 'reorder_level');
        }

        $format = $filters['format'];

        $writerType = $format === 'csv'
            ? ExcelWriter::CSV
            : ExcelWriter::XLSX;

        $fileName = 'bizflow-inventory-'
            . now()->format('Ymd-His')
            . ".{$format}";

        return Excel::download(
            new InventoryExport($query),
            $fileName,
            $writerType
        );
    }
}