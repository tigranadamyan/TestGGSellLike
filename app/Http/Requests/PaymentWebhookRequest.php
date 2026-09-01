<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PaymentEventStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:255'],
            // Deliberately no exists:orders,id — the contract requires a fast 200 for
            // an accepted event. Rejecting an early webhook with 422 would tell the
            // payment system "delivered, don't retry" and lose the payment.
            'order_id' => ['required', 'integer'],
            'status' => ['required', Rule::in(PaymentEventStatus::cases())],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'created_at' => ['required', 'date'],
        ];
    }
}
