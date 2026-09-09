<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\OutOfStockException;
use App\Models\Product;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly OrderService $orders,
    ) {}

    public function show(): InertiaResponse
    {
        return Inertia::render('Cart', $this->payload());
    }

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'exists:products,sku'],
            'qty' => ['sometimes', 'integer', 'min:1', 'max:'.CartService::MAX_QTY],
        ]);

        $this->cart->add(
            Product::where('sku', $data['sku'])->firstOrFail(),
            (int) ($data['qty'] ?? 1),
        );

        return response()->json($this->payload());
    }

    public function update(Request $request, string $sku): JsonResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:0', 'max:'.CartService::MAX_QTY],
        ]);

        $this->cart->setQuantity($sku, (int) $data['qty']);

        return response()->json($this->payload());
    }

    public function remove(string $sku): JsonResponse
    {
        $this->cart->remove($sku);

        return response()->json($this->payload());
    }

    /**
     * Turn the cart into orders.
     *
     * A price that moved while the cart sat open stops checkout once: the shopper
     * is shown the new figure and has to confirm it. Only the second attempt can
     * charge, and it charges today's price — never the one captured at add time.
     */
    public function checkout(): JsonResponse
    {
        if ($this->cart->raw() === []) {
            return response()->json([
                'error' => 'empty_cart',
                'message' => 'Корзина пуста.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $changed = $this->cart->changedLines();

        if ($changed !== []) {
            // They have now been shown the new prices, so the next attempt may pass.
            $this->cart->acknowledgePrices();

            return response()->json([
                'error' => 'price_changed',
                'message' => 'Цена изменилась, пока товар лежал в корзине. Проверьте сумму и подтвердите заказ.',
                'changed' => $changed,
                ...$this->payload(),
            ], Response::HTTP_CONFLICT);
        }

        $created = [];
        $soldOut = [];

        foreach ($this->cart->lines() as $line) {
            $product = Product::where('sku', $line['sku'])->first();

            if (! $product) {
                continue;
            }

            for ($i = 0; $i < $line['qty']; $i++) {
                try {
                    $order = $this->orders->createOrder($product);
                    $created[] = [
                        'id' => $order->id,
                        'sku' => $order->sku,
                        'price' => $order->price,
                        'currency' => $order->currency,
                    ];
                } catch (OutOfStockException) {
                    // Someone took the last key between the cart page and this
                    // click. Report the shortfall instead of failing the basket.
                    $soldOut[] = ['sku' => $line['sku'], 'name' => $line['name']];
                    break;
                }
            }

            // Whatever was ordered leaves the cart; the shortfall stays visible.
            $ordered = count(array_filter($created, fn (array $o) => $o['sku'] === $line['sku']));

            if ($ordered >= $line['qty']) {
                $this->cart->remove($line['sku']);
            } else {
                $this->cart->setQuantity($line['sku'], $line['qty'] - $ordered);
            }
        }

        return response()->json([
            'orders' => $created,
            'sold_out' => $soldOut,
            ...$this->payload(),
        ], $created === [] ? Response::HTTP_CONFLICT : Response::HTTP_CREATED);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'lines' => $this->cart->lines(),
            'total' => $this->cart->total(),
            'count' => $this->cart->count(),
        ];
    }
}
