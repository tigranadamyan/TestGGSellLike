<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class ReconciliationService
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly LedgerService $ledger,
        private readonly CatalogService $catalog,
    ) {}

    /** @return array<string, mixed> */
    public function reconcile(): array
    {
        Log::info('reconciliation.started');

        $results = [
            'paid_not_delivered' => [],
            'delivered_not_paid' => [],
            'stale_delivering' => [],
            'out_of_stock' => [],
            'delivery_failed' => [],
            'orphan_events_applied' => [],
            'amount_mismatch' => [],
            'catalog_drift' => [],
            'recovered' => 0,
        ];

        // 0. Events accepted before their order existed — apply them now.
        $results['orphan_events_applied'] = $this->paymentService->applyOrphanEvents();

        // 1. Find paid but not delivered ("оплачен, но не выдан")
        $paidNotDelivered = Order::where('status', OrderStatus::Paid)
            ->whereDoesntHave('delivery', function ($q) {
                $q->where('status', 'completed');
            })
            ->get();

        foreach ($paidNotDelivered as $order) {
            $results['paid_not_delivered'][] = $order->id;
            $this->paymentService->triggerDeliveryIfNeeded($order);
            $results['recovered']++;
        }

        // 2. Find stale delivering (stuck for more than 5 minutes)
        $staleThreshold = now()->subMinutes(5);
        $staleDelivering = Order::where('status', OrderStatus::Delivering)
            ->where('updated_at', '<', $staleThreshold)
            ->get();

        foreach ($staleDelivering as $order) {
            $results['stale_delivering'][] = $order->id;
            \App\Jobs\DeliverProductJob::dispatch($order->id)->onQueue('deliveries');
            $results['recovered']++;
        }

        // 3. Find out_of_stock
        $outOfStock = Order::where('status', OrderStatus::OutOfStock)->get();
        foreach ($outOfStock as $order) {
            $results['out_of_stock'][] = $order->id;
            if ($order->product && $order->product->availableKeys()->exists()) {
                \App\Jobs\DeliverProductJob::dispatch($order->id)->onQueue('deliveries');
                $results['recovered']++;
            }
        }

        // 4. Find delivery_failed — retry
        $deliveryFailed = Order::where('status', OrderStatus::DeliveryFailed)->get();
        foreach ($deliveryFailed as $order) {
            $results['delivery_failed'][] = $order->id;
            \App\Jobs\DeliverProductJob::dispatch($order->id)->onQueue('deliveries');
            $results['recovered']++;
        }

        // 5. Anomaly: delivered but never paid ("выдан, но не оплачен")
        $deliveredNotPaid = Order::where('status', OrderStatus::Delivered)
            ->whereDoesntHave('paymentEvents', function ($q) {
                $q->where('status', 'paid');
            })
            ->get();

        foreach ($deliveredNotPaid as $order) {
            $results['delivered_not_paid'][] = $order->id;
        }

        // 6. Anomaly: the settled amount does not match what the order costs.
        $results['amount_mismatch'] = \App\Models\PaymentEvent::query()
            ->join('orders', 'orders.id', '=', 'payment_events.order_id')
            ->where('payment_events.status', 'paid')
            ->whereNotNull('payment_events.processed_at')
            ->whereRaw('ROUND(payment_events.amount, 2) <> ROUND(orders.price, 2)')
            ->pluck('orders.id')
            ->all();

        // 6b. The denormalised storefront counter is a cache — verify it.
        $results['catalog_drift'] = $this->catalog->drift();

        if ($results['catalog_drift'] !== []) {
            Log::warning('reconciliation.catalog_drift', ['rows' => $results['catalog_drift']]);
            $this->catalog->recount();
        }

        // 7. The money journal itself: it must always sum to zero, and the
        // outstanding obligation must equal the value of undelivered orders.
        $results['ledger'] = $this->ledger->report();

        if (! $results['ledger']['balanced'] || ! $results['ledger']['obligations_match']) {
            Log::error('reconciliation.ledger_anomaly', $results['ledger']);
        }

        Log::info('reconciliation.completed', $results);

        return $results;
    }
}
