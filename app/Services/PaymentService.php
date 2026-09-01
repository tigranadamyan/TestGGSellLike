<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentOutcome;
use App\Enums\PaymentEventStatus;
use App\Models\Order;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(
        private readonly LedgerService $ledger,
    ) {}

    /** @param  array<string,mixed>  $payload */
    public function processPayment(string $eventId, int $orderId, string $status, float $amount, string $currency, array $payload = []): PaymentOutcome
    {
        return DB::transaction(function () use ($eventId, $orderId, $status, $amount, $currency, $payload) {
            // Try to insert payment event — UNIQUE(event_id) prevents duplicates
            // Lock the order first (if it exists) so the whole apply is serialised.
            $order = Order::lockForUpdate()->find($orderId);

            // UNIQUE(event_id) is what makes redelivery idempotent. An event whose
            // order does not exist yet is still STORED (order_id null) so it can be
            // applied later — never dropped.
            try {
                $event = PaymentEvent::create([
                    'event_id' => $eventId,
                    'order_id' => $order?->id,
                    'claimed_order_id' => $orderId,
                    'status' => $status,
                    'amount' => $amount,
                    'currency' => $currency,
                    'payload' => $payload,
                    'created_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException) {
                Log::info('payment.duplicate_event', ['event_id' => $eventId]);

                return PaymentOutcome::Duplicate;
            }

            if (! $order) {
                Log::warning('payment.order_not_found_yet', [
                    'event_id' => $eventId,
                    'claimed_order_id' => $orderId,
                ]);

                return PaymentOutcome::PendingOrder;
            }

            return $this->applyEventToOrder($event, $order)
                ? PaymentOutcome::Applied
                : PaymentOutcome::Ignored;
        });
    }

    /**
     * Apply a stored event to its order. Called both on live delivery and by
     * reconciliation for events that arrived before their order existed.
     * Caller must hold the row lock on $order.
     */
    private function applyEventToOrder(PaymentEvent $event, Order $order): bool
    {
        // Only a `created` order reacts to payment. Anything else is already
        // decided, so a late or out-of-order event is a no-op, not an error.
        if ($order->status !== OrderStatus::Created) {
            Log::info('payment.order_not_created', [
                'order_id' => $order->id,
                'event_id' => $event->event_id,
                'current_status' => $order->status->value,
            ]);

            return false;
        }

        if ($event->status === PaymentEventStatus::Paid->value) {
            $order->transitionTo(OrderStatus::Paid);

            $this->ledger->recordPaymentReceived($order, (float) $event->amount, $event->currency);

            $event->update(['order_id' => $order->id, 'processed_at' => now()]);

            Log::info('payment.processed', [
                'order_id' => $order->id,
                'event_id' => $event->event_id,
            ]);

            return true;
        }

        if ($event->status === PaymentEventStatus::Failed->value) {
            $order->transitionTo(OrderStatus::PaymentFailed);

            $event->update(['order_id' => $order->id, 'processed_at' => now()]);

            Log::info('payment.failed', [
                'order_id' => $order->id,
                'event_id' => $event->event_id,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Replay events that were accepted before their order existed. Returns the
     * ids of orders that became paid as a result.
     *
     * @return list<int>
     */
    public function applyOrphanEvents(): array
    {
        $applied = [];

        $orphans = PaymentEvent::whereNull('order_id')
            ->whereNotNull('claimed_order_id')
            ->whereNull('processed_at')
            ->orderBy('created_at')
            ->get();

        foreach ($orphans as $event) {
            DB::transaction(function () use ($event, &$applied) {
                $order = Order::lockForUpdate()->find($event->claimed_order_id);

                if (! $order) {
                    return;
                }

                if ($this->applyEventToOrder($event, $order)) {
                    $applied[] = $order->id;
                }
            });
        }

        return $applied;
    }

    public function triggerDeliveryIfNeeded(Order $order): void
    {
        $order->refresh();

        if ($order->status === OrderStatus::Paid) {
            \App\Jobs\DeliverProductJob::dispatch($order->id)
                ->onQueue('deliveries');
        }
    }
}
