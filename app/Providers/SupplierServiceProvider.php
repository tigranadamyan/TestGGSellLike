<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\DeliveryService;
use App\Services\CatalogService;
use App\Services\LedgerService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use App\Services\ReservationService;
use App\Suppliers\Contracts\SupplierInterface;
use App\Suppliers\SupplierManager;
use Illuminate\Support\ServiceProvider;

class SupplierServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SupplierManager::class, function ($app) {
            return new SupplierManager($app);
        });

        $this->app->bind('supplier.a', function ($app) {
            return $app->make(SupplierManager::class)->driver('a');
        });

        $this->app->bind('supplier.b', function ($app) {
            return $app->make(SupplierManager::class)->driver('b');
        });

        $this->app->singleton(LedgerService::class, fn () => new LedgerService);

        $this->app->singleton(CatalogService::class, fn () => new CatalogService);

        $this->app->singleton(ReservationService::class, fn ($app) => new ReservationService(
            $app->make(CatalogService::class),
        ));

        $this->app->singleton(DeliveryService::class, function ($app) {
            return new DeliveryService(
                $app->make(SupplierManager::class),
                $app->make(LedgerService::class),
                $app->make(CatalogService::class),
            );
        });

        $this->app->singleton(PaymentService::class, function ($app) {
            return new PaymentService(
                $app->make(LedgerService::class),
                $app->make(ReservationService::class),
            );
        });

        $this->app->singleton(OrderService::class, function ($app) {
            return new OrderService(
                $app->make(ReservationService::class),
            );
        });

        $this->app->singleton(ReconciliationService::class, function ($app) {
            return new ReconciliationService(
                $app->make(PaymentService::class),
                $app->make(LedgerService::class),
                $app->make(CatalogService::class),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
