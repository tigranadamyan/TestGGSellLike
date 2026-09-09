<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Session;

/**
 * A session-backed cart.
 *
 * Each line remembers the price it was added at. That remembered price is never
 * what the shopper pays — it exists so the cart can tell them the price moved
 * while the item sat there, and so checkout can refuse to charge a figure they
 * have not seen.
 */
class CartService
{
    private const KEY = 'cart';

    /** Hard cap per line, so one cart cannot drain a product. */
    public const MAX_QTY = 10;

    /**
     * Raw contents: sku => ['qty' => int, 'price_at_add' => string].
     *
     * @return array<string, array{qty: int, price_at_add: string}>
     */
    public function raw(): array
    {
        /** @var array<string, array{qty: int, price_at_add: string}> */
        return Session::get(self::KEY, []);
    }

    public function add(Product $product, int $qty = 1): void
    {
        $cart = $this->raw();
        $sku = $product->sku;

        $current = $cart[$sku]['qty'] ?? 0;

        $cart[$sku] = [
            'qty' => min(self::MAX_QTY, max(1, $current + $qty)),
            // First add wins: re-adding must not quietly hide a price move.
            'price_at_add' => $cart[$sku]['price_at_add'] ?? (string) $product->price,
        ];

        Session::put(self::KEY, $cart);
    }

    public function setQuantity(string $sku, int $qty): void
    {
        $cart = $this->raw();

        if (! isset($cart[$sku])) {
            return;
        }

        if ($qty < 1) {
            unset($cart[$sku]);
        } else {
            $cart[$sku]['qty'] = min(self::MAX_QTY, $qty);
        }

        Session::put(self::KEY, $cart);
    }

    public function remove(string $sku): void
    {
        $cart = $this->raw();
        unset($cart[$sku]);
        Session::put(self::KEY, $cart);
    }

    public function clear(): void
    {
        Session::forget(self::KEY);
    }

    public function count(): int
    {
        return array_sum(array_column($this->raw(), 'qty'));
    }

    /**
     * Cart lines joined with live product data.
     *
     * `price` is always today's price. `price_at_add` and `price_changed` are what
     * let the page say "this went up while you were deciding".
     *
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        $cart = $this->raw();

        if ($cart === []) {
            return [];
        }

        $products = Product::whereIn('sku', array_keys($cart))->get()->keyBy('sku');

        $lines = [];

        foreach ($cart as $sku => $item) {
            $product = $products->get($sku);

            if (! $product) {
                continue; // Product disappeared from the catalogue.
            }

            $current = (string) $product->price;
            $addedAt = (string) $item['price_at_add'];

            $lines[] = [
                'sku' => $sku,
                'name' => $product->name,
                'type' => $product->type,
                'qty' => $item['qty'],
                'price' => $current,
                'price_at_add' => $addedAt,
                'price_changed' => $this->differs($current, $addedAt),
                'currency' => $product->currency,
                'available' => $product->available_keys_count,
                'in_stock' => $product->available_keys_count > 0,
                'line_total' => number_format((float) $current * $item['qty'], 2, '.', ''),
            ];
        }

        return $lines;
    }

    /**
     * Lines whose price moved since they were added.
     *
     * @return list<array<string, mixed>>
     */
    public function changedLines(): array
    {
        return array_values(array_filter($this->lines(), fn (array $line) => $line['price_changed']));
    }

    /**
     * Accept today's prices as seen. Called only after the shopper has been shown
     * them, which is what makes the checkout guard meaningful.
     */
    public function acknowledgePrices(): void
    {
        $cart = $this->raw();
        $products = Product::whereIn('sku', array_keys($cart))->get()->keyBy('sku');

        foreach ($cart as $sku => $item) {
            if ($product = $products->get($sku)) {
                $cart[$sku]['price_at_add'] = (string) $product->price;
            }
        }

        Session::put(self::KEY, $cart);
    }

    public function total(): string
    {
        $total = 0.0;

        foreach ($this->lines() as $line) {
            $total += (float) $line['line_total'];
        }

        return number_format($total, 2, '.', '');
    }

    /** Compare decimal strings by value, so "1290" and "1290.00" are the same price. */
    private function differs(string $a, string $b): bool
    {
        return bccomp(
            number_format((float) $a, 2, '.', ''),
            number_format((float) $b, 2, '.', ''),
            2
        ) !== 0;
    }
}
