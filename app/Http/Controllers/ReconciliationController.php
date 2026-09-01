<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ReconciliationController extends Controller
{
    public function __construct(
        private readonly ReconciliationService $reconciliationService,
    ) {}

    #[OA\Post(
        path: '/api/internal/reconciliation',
        operationId: 'reconciliationRun',
        description: <<<'MD'
        Прогон сверки. Не только находит расхождения, но и **чинит** их: заново
        ставит в очередь застрявшие выдачи, применяет вебхуки, пришедшие раньше
        своего заказа, и пересчитывает счётчик остатков при обнаруженном дрейфе.
        Счётчик `recovered` — сколько заказов было переотправлено в доставку.

        Операция служебная: маршрут `/api/internal/*` не должен быть доступен
        снаружи периметра.
        MD,
        summary: 'Запустить сверку',
        tags: ['Internal'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Итог сверки',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'paid_not_delivered', description: 'Оплачены, но не выданы — переотправлены в доставку.', type: 'array', items: new OA\Items(type: 'integer')),
                                new OA\Property(property: 'delivered_not_paid', description: 'Аномалия: выданы без оплаты. Только сигнал, автоматически не чинится.', type: 'array', items: new OA\Items(type: 'integer')),
                                new OA\Property(property: 'stale_delivering', description: 'Висят в `delivering` дольше 5 минут — переотправлены.', type: 'array', items: new OA\Items(type: 'integer')),
                                new OA\Property(property: 'out_of_stock', type: 'array', items: new OA\Items(type: 'integer')),
                                new OA\Property(property: 'delivery_failed', type: 'array', items: new OA\Items(type: 'integer')),
                                new OA\Property(property: 'orphan_events_applied', description: 'Вебхуки, пришедшие раньше заказа и применённые сейчас.', type: 'array', items: new OA\Items(type: 'integer')),
                                new OA\Property(property: 'amount_mismatch', description: 'Сумма платежа не совпала со стоимостью заказа.', type: 'array', items: new OA\Items(type: 'integer')),
                                new OA\Property(property: 'catalog_drift', description: 'Товары, где кэш остатков разошёлся с фактом. Пересчитывается автоматически.', type: 'array', items: new OA\Items(type: 'object')),
                                new OA\Property(property: 'recovered', description: 'Сколько заказов переотправлено в доставку.', type: 'integer', example: 3),
                                new OA\Property(property: 'ledger', description: 'Отчёт по журналу денег: `balanced` — сходится ли он в ноль, `obligations_match` — равны ли обязательства стоимости невыданных заказов.', type: 'object'),
                            ],
                            type: 'object',
                        ),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        $results = $this->reconciliationService->reconcile();

        return response()->json(['data' => $results]);
    }
}
