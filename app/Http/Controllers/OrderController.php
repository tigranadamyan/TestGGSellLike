<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\OutOfStockException;
use App\Http\Requests\CreateOrderRequest;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    #[OA\Post(
        path: '/api/orders',
        operationId: 'ordersStore',
        description: <<<'MD'
        Создаёт заказ в статусе `created`. Ключ на этом шаге **не** резервируется и
        не выдаётся — выдача начинается только после успешного вебхука оплаты.
        MD,
        summary: 'Создать заказ',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sku'],
                properties: [
                    new OA\Property(
                        property: 'sku',
                        description: 'SKU существующего товара — берётся из `GET /api/catalog`.',
                        type: 'string',
                        example: 'KEY-CS2-PRIME',
                    ),
                ],
                type: 'object',
            ),
        ),
        tags: ['Orders'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Заказ создан',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Order')],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 409,
                description: 'Последний ключ забрали в этот момент — товар раскуплен',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'sold_out'),
                        new OA\Property(property: 'message', type: 'string', example: 'Этот товар только что раскупили.'),
                        new OA\Property(property: 'sku', type: 'string', example: 'KEY-CS2-PRIME'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'SKU отсутствует или такого товара нет в каталоге',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(CreateOrderRequest $request): JsonResponse
    {
        $product = Product::where('sku', $request->validated('sku'))->firstOrFail();

        // Check for idempotency key to prevent duplicate orders
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if ($idempotencyKey) {
            $existingOrder = Order::where('idempotency_key', $idempotencyKey)->first();
            if ($existingOrder) {
                return response()->json([
                    'data' => [
                        'id' => $existingOrder->id,
                        'sku' => $existingOrder->sku,
                        'price' => $existingOrder->price,
                        'currency' => $existingOrder->currency,
                        'status' => $existingOrder->status->value,
                        'created_at' => $existingOrder->created_at,
                    ],
                ], Response::HTTP_OK);
            }
        }

        try {
            $order = $this->orderService->createOrder($product, $idempotencyKey);
        } catch (OutOfStockException) {
            // Someone took the last key a moment earlier. This is a normal
            // outcome of the race, not a failure — say so plainly.
            return response()->json([
                'error' => 'sold_out',
                'message' => 'Этот товар только что раскупили.',
                'sku' => $product->sku,
            ], Response::HTTP_CONFLICT);
        }

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

    #[OA\Get(
        path: '/api/orders/{id}',
        operationId: 'ordersShow',
        description: <<<'MD'
        Текущее состояние заказа. Поле `delivery.code` заполняется только когда
        доставка дошла до `completed` — до этого момента это нормальный `null`,
        а не ошибка.
        MD,
        summary: 'Получить заказ',
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Идентификатор заказа из ответа `POST /api/orders`.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                example: 2,
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Заказ',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Order')],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Заказ не найден',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'error', type: 'string', example: 'Order not found')],
                    type: 'object',
                ),
            ),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        $order = $this->orderService->getOrder($id);

        if (! $order) {
            return response()->json(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
        }

        $reservation = $this->orderService->getReservation($order);

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
                'reservation' => $reservation ? [
                    'expires_at' => $reservation->expires_at->toISOString(),
                    'remaining_seconds' => $this->orderService->remainingReservationSeconds($reservation),
                    'is_active' => $reservation->isActive(),
                ] : null,
                'created_at' => $order->created_at,
            ],
        ]);
    }
}
