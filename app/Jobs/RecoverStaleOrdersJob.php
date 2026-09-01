<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RecoverStaleOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(ReconciliationService $reconciliationService): void
    {
        $reconciliationService->reconcile();
    }
}
