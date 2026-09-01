<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CatalogRequest;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog,
    ) {}

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
