<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Delivery;
use App\Models\MoneyMovement;
use App\Services\LedgerService;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\ProductKey;
use App\Models\SupplierRequest;
use Illuminate\Console\Command;

/**
 * Wall-clock concurrency check against a running server.
 *
 * The PHPUnit suite proves the same invariants in-process, but cannot produce
 * real OS-level parallelism. This fires N genuinely simultaneous webhooks with
 * curl_multi and then asserts the money/inventory invariants directly in the DB.
 *
 *   php artisan serve --env=pgtest &   # PHP_CLI_SERVER_WORKERS=12
 *   php artisan --env=pgtest race:test --sku=KEY-CS2-PRIME
 */
class RaceTestCommand extends Command
{
    protected $signature = 'race:test
        {--url=http://127.0.0.1:8123 : Base URL of the running app}
        {--sku=KEY-CS2-PRIME : SKU to order}
        {--count=50 : Number of simultaneous webhooks}';

    protected $description = 'Fire N simultaneous payment webhooks at one order and verify exactly-once issuance';

    private int $failures = 0;

    private float $price = 0.0;

    public function handle(): int
    {
        $base = rtrim((string) $this->option('url'), '/');
        $sku = (string) $this->option('sku');
        $count = (int) $this->option('count');

        foreach (['same', 'distinct'] as $mode) {
            $label = $mode === 'same'
                ? "{$count} simultaneous webhooks, SAME event_id (at-least-once redelivery)"
                : "{$count} simultaneous webhooks, DISTINCT event_ids (concurrent events)";

            $this->newLine();
            $this->info($label);

            $orderId = $this->createOrder($base, $sku);
            if ($orderId === null) {
                return self::FAILURE;
            }

            $codes = $this->fireParallel($base, $orderId, $count, $mode);
            $this->line('  HTTP responses: '.json_encode($codes));

            $this->assertInvariants($orderId, $mode === 'same' ? 1 : $count);
        }

        $this->newLine();

        if ($this->failures > 0) {
            $this->error("RACE TEST FAILED ({$this->failures} violated invariant(s))");

            return self::FAILURE;
        }

        $this->info('RACE TEST PASSED — exactly-once issuance held under real concurrency.');

        return self::SUCCESS;
    }

    private function createOrder(string $base, string $sku): ?int
    {
        $ch = curl_init("$base/api/orders");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['sku' => $sku], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $decoded = json_decode((string) $body, true)['data'] ?? null;
        $id = $decoded['id'] ?? null;
        $this->price = (float) ($decoded['price'] ?? 0);

        if (! $id) {
            $this->error("  Could not create order (HTTP $status). Is the server running at $base?");

            return null;
        }

        $this->line("  order_id=$id");

        return (int) $id;
    }

    /** @return array<int,int> map of HTTP status => count */
    private function fireParallel(string $base, int $orderId, int $count, string $mode): array
    {
        $mh = curl_multi_init();
        $handles = [];

        for ($i = 0; $i < $count; $i++) {
            $eventId = $mode === 'same'
                ? "evt_race_{$orderId}"
                : "evt_race_{$orderId}_{$i}";

            $ch = curl_init("$base/api/webhooks/payment");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'event_id' => $eventId,
                    'order_id' => $orderId,
                    'status' => 'paid',
                    'amount' => $this->price,
                    'currency' => 'RUB',
                    'created_at' => '2025-01-01T12:00:00Z',
                ], JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        // Released together, then drained.
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.05);
        } while ($running > 0);

        $codes = [];
        foreach ($handles as $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $codes[$code] = ($codes[$code] ?? 0) + 1;
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);

        return $codes;
    }

    private function assertInvariants(int $orderId, int $expectedEvents): void
    {
        $order = Order::find($orderId);

        $this->check('order reached delivered', 'delivered', $order?->status->value);
        $this->check('payment events recorded (no loss)', $expectedEvents, PaymentEvent::where('claimed_order_id', $orderId)->count());
        // Postings, not rows: one balanced posting is two rows.
        $this->check('payment postings', 1, MoneyMovement::where('order_id', $orderId)
            ->where('type', 'payment_received')->distinct()->count('entry_group'));
        $this->check('revenue postings', 1, MoneyMovement::where('order_id', $orderId)
            ->where('type', 'revenue_recognised')->distinct()->count('entry_group'));
        $this->check('deliveries', 1, Delivery::where('order_id', $orderId)->count());
        $this->check('code issued', true, filled(Delivery::where('order_id', $orderId)->first()?->code));

        // A code comes from EITHER the local key pool OR a supplier. Exactly one
        // issuance source may be consumed, whichever path was taken.
        $localKeys = ProductKey::where('order_id', $orderId)->count();
        $supplierCodes = SupplierRequest::where('request_id', 'like', "req_{$orderId}_%")
            ->where('status', 'success')
            ->count();

        $this->check('issuance sources consumed', 1, $localKeys + $supplierCodes);
        $this->line("       (local keys: {$localKeys}, supplier codes: {$supplierCodes})");

        // The money journal must survive the race too.
        $ledger = app(LedgerService::class)->report();
        $this->check('ledger sums to zero', true, $ledger['balanced']);
        $this->check('no unbalanced postings', 0, count($ledger['unbalanced_groups']));
        $this->check('obligations match orders', true, $ledger['obligations_match']);
    }

    private function check(string $label, mixed $expected, mixed $actual): void
    {
        if ($expected === $actual) {
            $this->line(sprintf('  <fg=green>OK</> %-34s %s', $label, var_export($actual, true)));

            return;
        }

        $this->failures++;
        $this->line(sprintf(
            '  <fg=red>FAIL</> %-32s expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}
