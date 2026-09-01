<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Services\CatalogService;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    /** How many storefront items the landing page shows. */
    private const PREVIEW_SIZE = 8;

    public function __construct(
        private readonly CatalogService $catalog,
    ) {}

    public function __invoke(): Response
    {
        return Inertia::render('Welcome', [
            'catalog' => $this->catalog->storefront(perPage: self::PREVIEW_SIZE)['items'],
            'stats' => $this->stats(),
        ]);
    }

    /**
     * Counters for the landing page. Cached for a minute: they are decorative,
     * and an anonymous visitor should not cost three aggregates.
     *
     * @return array{products: int, available_keys: int, delivered: int}
     */
    private function stats(): array
    {
        return Cache::remember('welcome.stats', now()->addMinute(), fn () => [
            'products' => Product::query()->count(),
            'available_keys' => (int) Product::query()->sum('available_keys_count'),
            'delivered' => Order::query()->where('status', OrderStatus::Delivered)->count(),
        ]);
    }
}
