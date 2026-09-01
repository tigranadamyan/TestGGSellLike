<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CatalogRequest;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class CatalogController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog,
    ) {}

    #[OA\Get(
        path: '/api/catalog',
        operationId: 'catalogIndex',
        description: <<<'MD'
        Горячий запрос витрины. Пагинация keyset, а не OFFSET: `after` — это id
        последней увиденной позиции, поэтому стоимость страницы не растёт с её
        глубиной. Курсор следующей страницы приходит в `meta.next_cursor`;
        `null` означает, что данные закончились.
        MD,
        summary: 'Список товаров витрины',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(
                name: 'type',
                description: 'Фильтр по типу товара.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', maxLength: 64, enum: ['key', 'giftcard', 'subscription', 'topup']),
                example: 'key',
            ),
            new OA\Parameter(
                name: 'in_stock',
                description: 'Только позиции в наличии. По умолчанию `true`.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'boolean', default: true),
            ),
            new OA\Parameter(
                name: 'per_page',
                description: 'Размер страницы, 1..100.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1, default: 24),
            ),
            new OA\Parameter(
                name: 'after',
                description: 'Keyset-курсор: значение `meta.next_cursor` предыдущей страницы.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 0),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Страница витрины',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/CatalogItem'),
                        ),
                        new OA\Property(
                            property: 'meta',
                            properties: [
                                new OA\Property(
                                    property: 'next_cursor',
                                    description: '`null`, если это последняя страница.',
                                    type: 'integer',
                                    nullable: true,
                                    example: 42,
                                ),
                            ],
                            type: 'object',
                        ),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Некорректные параметры запроса',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function __invoke(CatalogRequest $request): JsonResponse
    {
        $result = $this->catalog->storefront(
            type: $request->validated('type'),
            inStockOnly: $request->boolean('in_stock', true),
            perPage: (int) ($request->validated('per_page') ?? 24),
            afterId: $request->validated('after') !== null ? (int) $request->validated('after') : null,
        );

        return response()->json([
            'data' => $result['items'],
            'meta' => [
                // Keyset cursor: pass back as ?after= to get the next page.
                'next_cursor' => $result['next_cursor'],
            ],
        ]);
    }
}
