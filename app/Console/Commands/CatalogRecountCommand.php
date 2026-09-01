<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CatalogService;
use Illuminate\Console\Command;

class CatalogRecountCommand extends Command
{
    protected $signature = 'catalog:recount {--dry-run : Only report drift, do not repair}';

    protected $description = 'Verify (and repair) the denormalised availability counter against product_keys';

    public function handle(CatalogService $catalog): int
    {
        $drift = $catalog->drift();

        if ($drift === []) {
            $this->info('Availability counters match product_keys — no drift.');

            return self::SUCCESS;
        }

        $this->warn(count($drift).' product(s) with drifted counters:');
        $this->table(['product_id', 'cached', 'actual'], array_map(
            fn ($r) => [$r['product_id'], $r['cached'], $r['actual']],
            array_slice($drift, 0, 50)
        ));

        if ($this->option('dry-run')) {
            $this->line('Dry run — nothing repaired.');

            return self::SUCCESS;
        }

        $repaired = $catalog->recount();
        $this->info("Repaired {$repaired} counter(s).");

        return self::SUCCESS;
    }
}
