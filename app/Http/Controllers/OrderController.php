<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CreateOrderRequest;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    public function store(CreateOrderRequest $request): JsonResponse
    {
        $product = Product::where('sku', $request->validated('sku'))->firstOrFail();
        $order = $this->orderService->createOrder($product);

        return response()->json([
            'data' => [
                'id' => $order->id,
                'sku' => $order->sku,
                'price' => $order->price,
                'currency' => $order->currency,
                'status' => $order->status->value,
                'created_at' => $order->created_at,
            ],
        ], Response::HTTP_CREATED);
    }

    public function show(int $id): JsonResponse
    {
        $order = $this->orderService->getOrder($id);

        if (! $order) {
            return response()->json(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => [
                'id' => $order->id,
                'sku' => $order->sku,
                'price' => $order->price,
                'currency' => $order->currency,
                'status' => $order->status->value,
                'delivery' => $order->delivery ? [
                    'status' => $order->delivery->status->value,
                    'code' => $order->delivery->code,
                    'supplier' => $order->delivery->supplier,
                ] : null,
                'created_at' => $order->created_at,
            ],
        ]);
    }
}
