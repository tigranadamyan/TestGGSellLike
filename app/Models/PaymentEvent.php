<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    protected $table = 'payment_events';

    protected $fillable = ['event_id', 'order_id', 'claimed_order_id', 'status', 'amount', 'currency', 'payload', 'created_at', 'processed_at'];

    protected $casts = [
        'amount' => 'decimal:2',
        'payload' => 'array',
        'created_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public $timestamps = false;

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
