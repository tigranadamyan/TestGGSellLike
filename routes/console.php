<?php

use App\Jobs\CancelExpiredReservations;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cancel expired reservations every minute
Schedule::job(new CancelExpiredReservations(app(\App\Services\ReservationService::class)))->everyMinute();
