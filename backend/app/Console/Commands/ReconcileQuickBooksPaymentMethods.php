<?php

namespace App\Console\Commands;

use App\Models\QuickBooksConnection;
use App\Services\QuickBooksPaymentsImporter;
use Illuminate\Console\Command;
use Throwable;

class ReconcileQuickBooksPaymentMethods extends Command
{
    protected $signature =
        'quickbooks:reconcile-payment-methods
        {connectionId : QuickBooks connection ID}
        {--apply : Apply the verified payment-method corrections}';

    protected $description =
        'Preview or reconcile existing QuickBooks payment methods';

    public function handle(
        QuickBooksPaymentsImporter $importer
    ): int {
        $connection = QuickBooksConnection::query()
            ->find((int) $this->argument('connectionId'));

        if (! $connection) {
            $this->error('QuickBooks connection not found.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        try {
            $result = $importer->reconcileExistingPaymentMethods(
                $connection,
                (int) $connection->tenant_id,
                $apply
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(
            $apply
                ? 'Reconciliation results (apply mode)'
                : 'Reconciliation preview — no database changes made'
        );

        $this->line(
            'Payment records scanned: '
            . $result['payment_records_scanned']
        );

        $this->line(
            'Mappings checked: '
            . $result['mappings_checked']
        );

        $this->line(
            'Unresolved methods: '
            . $result['unresolved_methods']
        );

        if ($result['candidates'] !== []) {
            $this->table(
                [
                    'QuickBooks ID',
                    'Source method ID',
                    'Source method',
                    'Bizflow method',
                    'Rows eligible',
                ],
                array_map(
                    fn (array $candidate): array => [
                        $candidate['quickbooks_id'],
                        $candidate['source_method_id'],
                        $candidate['source_method_name'],
                        $candidate['bizflow_method'],
                        $candidate['eligible_rows'],
                    ],
                    $result['candidates']
                )
            );
        } else {
            $this->info(
                'No existing payment rows qualify for correction.'
            );
        }

        if ($apply) {
            $this->info(
                'Payment rows updated: '
                . $result['updated_payment_rows']
            );

            $this->info(
                'Mapping records updated: '
                . $result['updated_mappings']
            );
        } else {
            $this->warn(
                'Nothing was changed. Review the rows above before using --apply.'
            );

            $this->line(
                'Eligible payment rows: '
                . $result['eligible_payment_rows']
            );
        }

        return self::SUCCESS;
    }
}