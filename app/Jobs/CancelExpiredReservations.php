<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CancelExpiredReservations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds before the job should be tried again.
     */
    public int $backoff = 60;

    /**
     * The maximum number of unhandled exceptions before the job is failed.
     */
    public int $tries = 3;

    public function __construct(
        private readonly ReservationService $reservationService,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $cancelledCount = $this->reservationService->cancelExpiredReservations();

        if ($cancelledCount > 0) {
            Log::info('reservations.expired_cancelled', [
                'count' => $cancelledCount,
            ]);
        }
    }
}
