<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $fillable = ['order_id', 'request_id', 'supplier', 'status', 'code', 'attempts', 'last_error'];

    protected $casts = [
        'status' => DeliveryStatus::class,
        'attempts' => 'integer',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
