<?php

namespace App\Services;

use App\Models\MigrationSession;
use RuntimeException;
use Illuminate\Support\Facades\Storage;

class MigrationCsvAnalyzer
{
    public function analyze(MigrationSession $session): array
    {
        if (!$session->file_path) {
            throw new RuntimeException(
                'The migration session does not have an uploaded file.'
            );
        }

        $stream = Storage::disk('local')->readStream($session->file_path);

        if ($stream === false) {
            throw new RuntimeException(
                'Unable to open the migration file.'
            );
        }

        $headers = fgetcsv($stream);
        $rowCount = 0;
        $sampleRows = [];

        $entityType = $this->detectEntityType($headers ?: []);

        while (($row = fgetcsv($stream)) !== false) {
            $rowCount++;

            if (count($sampleRows) < 5 && $headers) {
                $sampleRows[] = array_combine($headers, $row);
            }
        }

        fclose($stream);

        return [
            'entity_type' => $entityType,
            'headers' => $headers ?: [],
            'row_count' => $rowCount,
            'sample_rows' => $sampleRows,
        ];
    }

    private function detectEntityType(array $headers): ?string
    {
        $normalized = array_map(
            fn ($header) => strtolower(trim($header)),
            $headers
        );

        if (
            in_array('customer', $normalized, true) &&
            (
                in_array('email', $normalized, true) ||
                in_array('phone', $normalized, true) ||
                in_array('company', $normalized, true)
            )
        ) {
            return 'customers';
        }

        if (
            in_array('vendor', $normalized, true) &&
            in_array('email', $normalized, true)
        ) {
            return 'suppliers';
        }

        if (
            in_array('product/service name', $normalized, true) &&
            in_array('sku', $normalized, true)
        ) {
            return 'catalog_items';
        }

        if (
            in_array('invoice number', $normalized, true) &&
            in_array('customer', $normalized, true) &&
            in_array('invoice date', $normalized, true)
        ) {
            return 'invoices';
        }

        if (
            in_array('date', $normalized, true) &&
            in_array('payee', $normalized, true) &&
            in_array('category', $normalized, true) &&
            in_array('amount', $normalized, true)
        ) {
            return 'expenses';
        }

        if (
            in_array('payment date', $normalized, true) &&
            in_array('customer', $normalized, true) &&
            in_array('invoice number', $normalized, true) &&
            in_array('amount', $normalized, true)
        ) {
            return 'payments';
        }

        return null;
    }
}