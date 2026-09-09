<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_key_id',
        'expires_at',
        'cancelled_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductKey, $this> */
    public function productKey(): BelongsTo
    {
        return $this->belongsTo(ProductKey::class);
    }

    /**
     * Check if this reservation is still active (not cancelled and not expired).
     */
    public function isActive(): bool
    {
        return $this->cancelled_at === null && $this->expires_at->isFuture();
    }

    /**
     * Check if this reservation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Cancel this reservation.
     */
    public function cancel(): void
    {
        $this->update(['cancelled_at' => now()]);
    }
}
