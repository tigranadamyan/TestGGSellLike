<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Models\MoneyMovement;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Append-only double-entry ledger.
 *
 * Every business event writes a balanced group of rows: the signed amounts within
 * one `entry_group` always sum to zero, so the ledger as a whole always sums to
 * zero too. Nothing is ever updated or deleted — a correction is a new entry.
 *
 * Accounts:
 *   cash              money received from the payment system
 *   deferred_revenue  paid but not yet delivered (what we owe the customer)
 *   revenue           recognised when the code is actually handed over
 */
class LedgerService
{
    /** Payment confirmed: cash comes in, and we now owe the customer a product. */
    public function recordPaymentReceived(Order $order, float $amount, string $currency): void
    {
        $this->post($order, LedgerEntryType::PaymentReceived, $currency, [
            [LedgerAccount::Cash, $amount],
            [LedgerAccount::DeferredRevenue, -$amount],
        ]);
    }

    /** Code handed over: the obligation is discharged and becomes revenue. */
    public function recordRevenueRecognised(Order $order): void
    {
        // Idempotent: delivery may be retried, revenue is recognised once.
        if ($this->hasEntry($order, LedgerEntryType::RevenueRecognised)) {
            return;
        }

        // Recognise exactly what was actually received for this order, never the
        // list price. Discharging a different amount than was raised would leave a
        // residue in deferred_revenue for every order settled at the wrong amount.
        // A price/settlement discrepancy is a separate anomaly, surfaced by
        // ReconciliationService as `amount_mismatch`.
        $received = round((float) MoneyMovement::where('order_id', $order->id)
            ->where('account', LedgerAccount::Cash->value)
            ->sum('amount'), 2);

        if ($received <= 0.0) {
            // Delivered without any recorded payment. Nothing to recognise; the
            // "delivered but not paid" check reports it.
            Log::warning('ledger.revenue_without_payment', ['order_id' => $order->id]);

            return;
        }

        $this->post($order, LedgerEntryType::RevenueRecognised, $order->currency, [
            [LedgerAccount::DeferredRevenue, $received],
            [LedgerAccount::Revenue, -$received],
        ]);
    }

    /** Money returned: the obligation is discharged by paying the customer back. */
    public function recordRefund(Order $order, float $amount, string $currency): void
    {
        if ($this->hasEntry($order, LedgerEntryType::Refund)) {
            return;
        }

        $this->post($order, LedgerEntryType::Refund, $currency, [
            [LedgerAccount::DeferredRevenue, $amount],
            [LedgerAccount::Cash, -$amount],
        ]);
    }

    public function hasEntry(Order $order, LedgerEntryType $type): bool
    {
        return MoneyMovement::where('order_id', $order->id)
            ->where('type', $type->value)
            ->exists();
    }

    /**
     * Write one balanced group. Refuses to persist anything that does not sum to
     * zero — a bug in a caller must never be able to unbalance the ledger.
     *
     * @param  list<array{0: LedgerAccount, 1: float}>  $legs
     */
    private function post(Order $order, LedgerEntryType $type, string $currency, array $legs): void
    {
        $sum = 0.0;
        foreach ($legs as [$account, $amount]) {
            $sum += $amount;
        }

        // Compare in minor units to avoid float noise.
        if ((int) round($sum * 100) !== 0) {
            throw new \LogicException(
                "Unbalanced ledger entry [{$type->value}] for order {$order->id}: sums to {$sum}"
            );
        }

        $group = (string) Str::uuid();

        DB::transaction(function () use ($order, $type, $currency, $legs, $group) {
            foreach ($legs as [$account, $amount]) {
                MoneyMovement::create([
                    'order_id' => $order->id,
                    'account' => $account->value,
                    'entry_group' => $group,
                    'type' => $type->value,
                    'amount' => $amount,
                    'currency' => $currency,
                ]);
            }
        });

        Log::info('ledger.posted', [
            'order_id' => $order->id,
            'type' => $type->value,
            'entry_group' => $group,
            'currency' => $currency,
        ]);
    }

    /**
     * Balance per account plus the invariants that must always hold.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $balances = MoneyMovement::query()
            ->selectRaw('account, SUM(amount) as balance')
            ->groupBy('account')
            ->pluck('balance', 'account')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();

        foreach (LedgerAccount::cases() as $account) {
            $balances[$account->value] ??= 0.0;
        }

        $total = round(array_sum($balances), 2);

        // Every unbalanced group is a hard corruption signal.
        $unbalancedGroups = MoneyMovement::query()
            ->selectRaw('entry_group')
            ->groupBy('entry_group')
            ->havingRaw('ROUND(SUM(amount), 2) <> 0')
            ->pluck('entry_group')
            ->all();

        // deferred_revenue is a liability, so it is carried negative. Its magnitude
        // must equal the value of orders that are paid but not yet delivered.
        $owed = round(-1 * (float) $balances[LedgerAccount::DeferredRevenue->value], 2);

        $undeliveredValue = round((float) Order::query()
            ->whereIn('status', ['paid', 'delivering', 'out_of_stock', 'delivery_failed'])
            ->sum('price'), 2);

        return [
            'balances' => $balances,
            'total_balance' => $total,
            'balanced' => $total === 0.0,
            'unbalanced_groups' => $unbalancedGroups,
            'owed_to_customers' => $owed,
            'undelivered_order_value' => $undeliveredValue,
            'obligations_match' => $owed === $undeliveredValue,
        ];
    }
}
