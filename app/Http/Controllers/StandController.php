<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\OutOfStockException;
use App\Models\Product;
use App\Services\CatalogService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * A self-serve page for reviewing the stand: the checks that would otherwise be
 * run with curl are buttons here, so nobody needs a terminal to see the live
 * storefront and the last-unit race behave.
 *
 * Demo-only. Every action below rewrites catalogue data, so the routes refuse to
 * run outside a local/staging environment.
 */
class StandController extends Controller
{
    /** SKUs the page drives, kept in a known state by `reset`. */
    private const LIVE_SKU = 'KEY-CS2-PRIME';
    private const RACE_SKU = 'GIFT-ROBLOX-800';
    private const PRICE_SKU = 'KEY-GTA5';

    public function __construct(
        private readonly OrderService $orders,
        private readonly CatalogService $catalog,
    ) {}

    public function show(): InertiaResponse
    {
        return Inertia::render('Stand', [
            'skus' => [
                'live' => self::LIVE_SKU,
                'race' => self::RACE_SKU,
                'price' => self::PRICE_SKU,
            ],
            'products' => $this->snapshot(),
        ]);
    }

    /** Put the three demo products back to their starting state. */
    public function reset(): JsonResponse
    {
        if ($blocked = $this->guard()) {
            return $blocked;
        }

        Artisan::call('demo:stock', ['sku' => self::LIVE_SKU, 'count' => 2]);
        Artisan::call('demo:stock', ['sku' => self::RACE_SKU, 'count' => 1]);
        Artisan::call('demo:stock', ['sku' => self::PRICE_SKU, 'count' => 5]);
        Artisan::call('demo:price', ['sku' => self::PRICE_SKU, 'price' => '1990.00']);

        return response()->json([
            'message' => 'Склад и цены возвращены в исходное состояние.',
            'products' => $this->snapshot(),
        ]);
    }

    /**
     * One purchase, exactly as the documented curl would do it. The point is that
     * the storefront learns about it over the socket, not from this response.
     */
    public function buy(Request $request): JsonResponse
    {
        if ($blocked = $this->guard()) {
            return $blocked;
        }

        $sku = (string) $request->input('sku', self::LIVE_SKU);
        $product = Product::where('sku', $sku)->first();

        if (! $product) {
            return response()->json(['message' => "Нет такого товара: {$sku}"], Response::HTTP_NOT_FOUND);
        }

        try {
            $order = $this->orders->createOrder($product);

            return response()->json([
                'status' => 201,
                'body' => [
                    'id' => $order->id,
                    'sku' => $order->sku,
                    'price' => $order->price,
                    'status' => $order->status->value,
                ],
                'products' => $this->snapshot(),
            ]);
        } catch (OutOfStockException) {
            return response()->json([
                'status' => 409,
                'body' => [
                    'error' => 'sold_out',
                    'message' => 'Этот товар только что раскупили.',
                    'sku' => $sku,
                ],
                'products' => $this->snapshot(),
            ]);
        }
    }

    /**
     * Two buyers at once, for real: both calls leave together over HTTP, so the
     * race is settled by the database rather than by PHP running them in order.
     */
    public function race(): JsonResponse
    {
        if ($blocked = $this->guard()) {
            return $blocked;
        }

        Artisan::call('demo:stock', ['sku' => self::RACE_SKU, 'count' => 1]);

        $base = 'http://127.0.0.1:'.(env('PORT') ?: '8000');

        $responses = Http::pool(fn ($pool) => [
            $pool->as('buyer_1')->acceptJson()->timeout(15)
                ->post($base.'/api/orders', ['sku' => self::RACE_SKU]),
            $pool->as('buyer_2')->acceptJson()->timeout(15)
                ->post($base.'/api/orders', ['sku' => self::RACE_SKU]),
        ]);

        $result = [];

        foreach (['buyer_1', 'buyer_2'] as $name) {
            $response = $responses[$name] ?? null;

            $result[] = [
                'buyer' => $name === 'buyer_1' ? 'Покупатель 1' : 'Покупатель 2',
                'status' => $response instanceof \Illuminate\Http\Client\Response ? $response->status() : 0,
                'body' => $response instanceof \Illuminate\Http\Client\Response ? $response->json() : ['error' => 'no_response'],
            ];
        }

        return response()->json([
            'results' => $result,
            'products' => $this->snapshot(),
        ]);
    }

    /** Move a price and push it to every open page. */
    public function price(Request $request): JsonResponse
    {
        if ($blocked = $this->guard()) {
            return $blocked;
        }

        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:1', 'max:1000000'],
        ]);

        $product = Product::where('sku', self::PRICE_SKU)->firstOrFail();
        $old = (string) $product->price;
        $new = number_format((float) $data['price'], 2, '.', '');

        $this->catalog->updatePrice($product->id, $new);

        return response()->json([
            'message' => "{$product->sku}: {$old} → {$new} ₽",
            'products' => $this->snapshot(),
        ]);
    }

    /** Current state of the three demo products. */
    private function snapshot(): array
    {
        // Fixed order: the cards must not reshuffle under the reviewer between
        // one action and the next.
        $order = [self::LIVE_SKU, self::RACE_SKU, self::PRICE_SKU];

        return Product::whereIn('sku', $order)
            ->get(['sku', 'name', 'price', 'currency', 'available_keys_count'])
            ->sortBy(fn (Product $p) => array_search($p->sku, $order, true))
            ->map(fn (Product $p) => [
                'sku' => $p->sku,
                'name' => $p->name,
                'price' => (string) $p->price,
                'currency' => $p->currency,
                'available' => $p->available_keys_count,
            ])
            ->values()
            ->all();
    }

    /** Demo actions rewrite catalogue data, so keep them out of production. */
    private function guard(): ?JsonResponse
    {
        if (app()->environment('production')) {
            return response()->json([
                'message' => 'Демо-действия отключены в production.',
            ], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
