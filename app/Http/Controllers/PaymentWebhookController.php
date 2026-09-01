<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentOutcome;
use App\Http\Requests\PaymentWebhookRequest;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function __invoke(PaymentWebhookRequest $request): JsonResponse
    {
        $outcome = $this->paymentService->processPayment(
            eventId: $request->validated('event_id'),
            orderId: $request->validated('order_id'),
            status: $request->validated('status'),
            amount: $request->validated('amount'),
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
