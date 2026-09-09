<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// Hot storefront read: no join, keyset pagination.
Route::get('/catalog', CatalogController::class);

// Fulltext search
Route::get('/search', SearchController::class);

Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{id}', [OrderController::class, 'show']);

Route::post('/webhooks/payment', PaymentWebhookController::class);

Route::post('/internal/reconciliation', ReconciliationController::class);
