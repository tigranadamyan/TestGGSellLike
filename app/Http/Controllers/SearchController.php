<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SearchController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog,
    ) {}

    #[OA\Get(
        path: '/api/search',
        operationId: 'searchIndex',
        description: <<<'MD'
        Полнотекстовый поиск по каталогу. Использует PostgreSQL GIN индекс для
        мгновенного поиска по тысячам SKU. Поддерживает фильтрацию по типу и
        наличию. Результаты ранжируются по релевантности.
        MD,
        summary: 'Поиск по каталогу',
        tags: ['Search'],
        parameters: [
            new OA\Parameter(
                name: 'q',
                description: 'Поисковый запрос.',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', minLength: 1),
                example: 'CS2',
            ),
            new OA\Parameter(
                name: 'type',
                description: 'Фильтр по типу товара.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', maxLength: 64, enum: ['key', 'giftcard', 'subscription', 'topup']),
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
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Результаты поиска',
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
                                    property: 'total',
                                    description: 'Общее количество результатов.',
                                    type: 'integer',
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
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1|max:255',
            'type' => 'nullable|string|in:key,giftcard,subscription,topup',
            'in_stock' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $result = $this->catalog->search(
            query: $request->input('q'),
            type: $request->input('type'),
            inStockOnly: $request->boolean('in_stock', true),
            perPage: (int) ($request->input('per_page') ?? 24),
        );

        return response()->json([
            'data' => $result['items'],
            'meta' => [
                'total' => $result['total'],
            ],
        ]);
    }
}
