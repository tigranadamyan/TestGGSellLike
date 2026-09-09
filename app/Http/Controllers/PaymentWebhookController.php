<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentOutcome;
use App\Http\Requests\PaymentWebhookRequest;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    #[OA\Post(
        path: '/api/webhooks/payment',
        operationId: 'paymentWebhook',
        description: <<<'MD'
        Приём события платёжной системы. Идемпотентен по `event_id`: повторная
        доставка того же события не создаёт вторую выдачу ключа.

        **Ответ всегда `200`, если событие принято.** Что именно произошло, сказано
        в `outcome`:

        | `outcome`       | Значение |
        |-----------------|----------|
        | `applied`       | Событие применено, заказ переведён в `paid`, запущена выдача. |
        | `duplicate`     | Такой `event_id` уже обработан, ничего не изменилось. |
        | `pending_order` | Заказа ещё нет — событие сохранено и будет применено сверкой. |
        | `ignored`       | Событие неприменимо к текущему состоянию заказа. |

        Поэтому `order_id` намеренно **не** проверяется на существование: ответ
        `422` на опережающий вебхук платёжная система прочитала бы как «доставлено,
        не повторять», и платёж потерялся бы. Статус `5xx` зарезервирован ровно за
        одним смыслом — «повтори запрос».
        MD,
        summary: 'Вебхук платёжной системы',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['event_id', 'order_id', 'status', 'amount', 'currency', 'created_at'],
                properties: [
                    new OA\Property(
                        property: 'event_id',
                        description: 'Ключ идемпотентности со стороны платёжной системы.',
                        type: 'string',
                        maxLength: 255,
                        example: 'evt_01HZX9K2',
                    ),
                    new OA\Property(property: 'order_id', type: 'integer', example: 2),
                    new OA\Property(property: 'status', type: 'string', enum: ['paid', 'failed'], example: 'paid'),
                    new OA\Property(property: 'amount', type: 'number', format: 'float', minimum: 0, example: 1290.00),
                    new OA\Property(property: 'currency', type: 'string', maxLength: 3, minLength: 3, example: 'RUB'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                ],
                type: 'object',
            ),
        ),
        tags: ['Payments'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Событие принято',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(
                            property: 'outcome',
                            type: 'string',
                            enum: ['applied', 'duplicate', 'pending_order', 'ignored'],
                            example: 'applied',
                        ),
                        new OA\Property(
                            property: 'duplicate',
                            description: 'Дубль по `event_id`. То же, что `outcome == "duplicate"`.',
                            type: 'boolean',
                            example: false,
                        ),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Событие не прошло валидацию и не было принято',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function __invoke(PaymentWebhookRequest $request): JsonResponse
    {
        $outcome = $this->paymentService->processPayment(
            eventId: $request->validated('event_id'),
            orderId: $request->validated('order_id'),
            status: $request->validated('status'),
            amount: (float) $request->validated('amount'),
            currency: $request->validated('currency'),
            payload: $request->all(),
        );

        // Only the webhook that actually moved the order to `paid` starts delivery.
        if ($outcome === PaymentOutcome::Applied) {
            $order = \App\Models\Order::find((int) $request->validated('order_id'));
            if ($order) {
                $this->paymentService->triggerDeliveryIfNeeded($order);
            }
        }

        // Always 200 — the contract reserves 5xx for "retry me".
        return response()->json([
            'status' => 'ok',
            'outcome' => $outcome->value,
            'duplicate' => $outcome === PaymentOutcome::Duplicate,
        ]);
    }
}
