<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;

class ReconciliationController extends Controller
{
    public function __construct(
        private readonly ReconciliationService $reconciliationService,
    ) {}

    public function __invoke(): JsonResponse
    {
        $results = $this->reconciliationService->reconcile();

        return response()->json(['data' => $results]);
    }
}
