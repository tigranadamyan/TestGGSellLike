<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ReconciliationService;
use Illuminate\Console\Command;

class ReconcileOrdersCommand extends Command
{
    protected $signature = 'orders:reconcile';

    protected $description = 'Reconcile stuck orders and trigger recovery';

    public function handle(ReconciliationService $reconciliationService): int
    {
        $results = $reconciliationService->reconcile();

        $this->info('Reconciliation completed:');
        $this->info('  Orphan events applied: '.count($results['orphan_events_applied']));
        $this->info('  Paid not delivered: '.count($results['paid_not_delivered']));
        $this->info('  Delivered not paid: '.count($results['delivered_not_paid']));
        $this->info('  Stale delivering: '.count($results['stale_delivering']));
        $this->info('  Out of stock: '.count($results['out_of_stock']));
        $this->info('  Delivery failed: '.count($results['delivery_failed']));
        $this->info('  Catalog counter drift: '.count($results['catalog_drift']).' (repaired)');
        $this->info('  Amount mismatch: '.count($results['amount_mismatch']));
        $this->info("  Recovered: {$results['recovered']}");

        $ledger = $results['ledger'];
        $this->newLine();
        $this->info('Money ledger:');
        foreach ($ledger['balances'] as $account => $balance) {
            $this->info(sprintf('  %-18s %12s', $account, number_format($balance, 2)));
        }
        $this->info(sprintf('  %-18s %12s', 'TOTAL', number_format($ledger['total_balance'], 2)));
        $this->info(sprintf('  owed to customers  %12s', number_format($ledger['owed_to_customers'], 2)));
        $this->info(sprintf('  undelivered value  %12s', number_format($ledger['undelivered_order_value'], 2)));

        if (! $ledger['balanced'] || ! $ledger['obligations_match']) {
            $this->error('  LEDGER DOES NOT RECONCILE');

            return Command::FAILURE;
        }

        $this->info('  ledger reconciles');

        return Command::SUCCESS;
    }
}
