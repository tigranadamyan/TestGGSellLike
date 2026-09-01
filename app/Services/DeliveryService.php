<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\IssueRequest;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductKeyStatus;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\ProductKey;
use App\Suppliers\SupplierManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeliveryService
{
    public function __construct(
        private readonly SupplierManager $supplierManager,
        private readonly LedgerService $ledger,
        private readonly CatalogService $catalog,
    ) {}

    public function deliver(Order $order): void
    {
        // Atomically claim the order. A conditional UPDATE is the whole exactly-once
        // guard for delivery: exactly one worker can move the order out of a
        // deliverable state, so retries, duplicate jobs and the reconciliation sweep
        // cannot run the issuance path concurrently. A row lock would also work but
        // would be held across slow supplier I/O.
        if (! $this->claim($order)) {
            Log::info('delivery.already_claimed', [
                'order_id' => $order->id,
                'status' => $order->fresh()?->status->value,
            ]);

            return;
        }

        $order->refresh();

        Log::info('delivery.started', [
            'order_id' => $order->id,
            'sku' => $order->sku,
        ]);

        // Try to reserve a local key first
        $key = $this->reserveKey($order);

        if ($key) {
            $this->completeDelivery($order, $key->code, 'local');

            return;
        }

        // No local keys — try external suppliers
        $this->deliverFromSupplier($order);
    }

    /**
     * Move the order into `delivering` only if it is currently in a deliverable
     * state. Returns false if another worker got there first, or if the order is
     * already finalised.
     */
    private function claim(Order $order): bool
    {
        $claimable = [
            OrderStatus::Paid->value,
            OrderStatus::OutOfStock->value,
            OrderStatus::DeliveryFailed->value,
        ];

        $claimed = Order::where('id', $order->id)
            ->whereIn('status', $claimable)
            ->update([
                'status' => OrderStatus::Delivering->value,
                'updated_at' => now(),
            ]);

        return $claimed === 1;
    }

    private function reserveKey(Order $order): ?ProductKey
    {
        // An earlier attempt for this order may already hold a key — reuse it
        // rather than burning a second one.
        $held = ProductKey::where('order_id', $order->id)
            ->whereIn('status', [ProductKeyStatus::Reserved, ProductKeyStatus::Issued])
            ->first();

        if ($held) {
            return $held;
        }

        try {
            return DB::transaction(function () use ($order) {
            $query = ProductKey::where('product_id', $order->product_id)
                ->where('status', ProductKeyStatus::Available)
                ->orderBy('id');

            // SQLite has no row-level locking; Postgres/MySQL use SKIP LOCKED so
            // concurrent workers grab different keys instead of serialising.
            if (DB::getDriverName() === 'sqlite') {
                $query->lockForUpdate();
            } else {
                $query->lock('for update skip locked');
            }

            $key = $query->first();

            if (! $key) {
                return null;
            }

            $key->update([
                'status' => ProductKeyStatus::Reserved,
                'order_id' => $order->id,
            ]);

            // Same transaction as the status change, so the storefront counter can
            // never drift because of a reservation.
            $this->catalog->adjustAvailability($order->product_id, -1);

            return $key;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // UNIQUE(order_id) fired: a concurrent worker reserved a key for this
            // order first. Fall back to whatever it reserved.
            return ProductKey::where('order_id', $order->id)
                ->whereIn('status', [ProductKeyStatus::Reserved, ProductKeyStatus::Issued])
                ->first();
        }
    }

    private function deliverFromSupplier(Order $order): void
    {
        $config = config('suppliers');
        $maxRetries = $config['max_retries'];
        $backoffs = $config['retry_backoff_ms'];

        $delivery = $this->getOrCreateDelivery($order);

        if ($order->status !== OrderStatus::Delivering) {
            $order->transitionTo(OrderStatus::Delivering);
        }

        if ($delivery->status !== DeliveryStatus::InProgress) {
            $delivery->update(['status' => DeliveryStatus::InProgress]);
        }

        $suppliers = ['a', 'b'];
        $lastError = null;
        $sawOutOfStock = false;
        $sawRealFailure = false;

        foreach ($suppliers as $supplierKey) {
            $supplier = $this->supplierManager->driver($supplierKey);
            $requestId = $this->generateRequestId($order, $supplierKey);

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                $delivery->increment('attempts');

                Log::info('supplier.request', [
                    'order_id' => $order->id,
                    'supplier' => $supplier->name(),
                    'request_id' => $requestId,
                    'attempt' => $attempt,
                ]);

                try {
                    $result = $supplier->issue(new IssueRequest(
                        requestId: $requestId,
                        sku: $order->sku,
                        orderId: (string) $order->id,
                    ));

                    if ($result->success) {
                        $this->completeDelivery($order, $result->code, $supplier->name());

                        return;
                    }

                    $lastError = $result->error;

                    if ($result->isOutOfStock) {
                        $sawOutOfStock = true;

                        Log::warning('supplier.out_of_stock', [
                            'order_id' => $order->id,
                            'supplier' => $supplier->name(),
                            'request_id' => $requestId,
                        ]);

                        break;
                    }

                    if ($result->isTimeout) {
                        Log::warning('supplier.timeout', [
                            'order_id' => $order->id,
                            'supplier' => $supplier->name(),
                            'request_id' => $requestId,
                        ]);

                        if ($attempt < $maxRetries) {
                            $backoffMs = $backoffs[$attempt - 1] ?? end($backoffs);
                            usleep($backoffMs * 1000);
                        }

                        if ($attempt === $maxRetries) {
                            $sawRealFailure = true;
                        }

                        continue;
                    }

                    $sawRealFailure = true;

                    // Definite failure — move to next supplier
                    Log::warning('supplier.failure', [
                        'order_id' => $order->id,
                        'supplier' => $supplier->name(),
                        'error' => $result->error,
                    ]);

                    break;

                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    $sawRealFailure = true;

                    Log::error('supplier.exception', [
                        'order_id' => $order->id,
                        'supplier' => $supplier->name(),
                        'error' => $lastError,
                    ]);

                    break 2;
                }
            }
        }

        // All suppliers exhausted. Both outcomes are recoverable, but the spec
        // distinguishes them: nothing in stock anywhere vs. suppliers erroring.
        $delivery->update([
            'status' => DeliveryStatus::Failed,
            'last_error' => $lastError,
        ]);

        if ($order->status === OrderStatus::Delivering) {
            $order->transitionTo(
                $sawOutOfStock && ! $sawRealFailure
                    ? OrderStatus::OutOfStock
                    : OrderStatus::DeliveryFailed
            );
        }
    }

    private function completeDelivery(Order $order, string $code, string $supplier): void
    {
        DB::transaction(function () use ($order, $code, $supplier) {
            ProductKey::where('order_id', $order->id)
                ->where('status', ProductKeyStatus::Reserved)
                ->update(['status' => ProductKeyStatus::Issued]);

            // Ensure delivery record exists (may not exist for local key path)
            $order->delivery()->firstOrCreate(
                ['order_id' => $order->id],
                [
                    'request_id' => 'req_local_'.$order->id,
                    'supplier' => $supplier,
                    'status' => DeliveryStatus::Pending,
                ]
            );

            $order->delivery()->update([
                'status' => DeliveryStatus::Completed,
                'code' => $code,
                'supplier' => $supplier,
            ]);

            // Must go through delivering → delivered (state machine requirement)
            if (in_array($order->status, [OrderStatus::Paid, OrderStatus::OutOfStock, OrderStatus::DeliveryFailed], true)) {
                $order->transitionTo(OrderStatus::Delivering);
            }

            if ($order->status === OrderStatus::Delivering) {
                $order->transitionTo(OrderStatus::Delivered);
            }

            // The obligation created at payment time is now discharged.
            $this->ledger->recordRevenueRecognised($order);

            Log::info('delivery.completed', [
                'order_id' => $order->id,
                'supplier' => $supplier,
                'code' => $code,
            ]);
        });
    }

    private function getOrCreateDelivery(Order $order): Delivery
    {
        return Delivery::firstOrCreate(
            ['order_id' => $order->id],
            [
                'request_id' => 'req_' . Str::uuid(),
                'supplier' => 'pending',
                'status' => DeliveryStatus::Pending,
            ]
        );
    }

    private function generateRequestId(Order $order, string $supplier): string
    {
        return "req_{$order->id}_{$supplier}";
    }
}
