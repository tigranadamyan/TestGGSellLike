<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\OrderShowController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');
Route::get('/orders/{id}', OrderShowController::class)->name('orders.show');

// Cart lives in the session, so it stays on the web middleware group.
Route::get('/cart', [CartController::class, 'show'])->name('cart');
Route::post('/cart', [CartController::class, 'add'])->name('cart.add');
Route::patch('/cart/{sku}', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart/{sku}', [CartController::class, 'remove'])->name('cart.remove');
Route::post('/cart/checkout', [CartController::class, 'checkout'])->name('cart.checkout');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
