<?php

use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\OrderShowController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');
Route::get('/orders/{id}', OrderShowController::class)->name('orders.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
