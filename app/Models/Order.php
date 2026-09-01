<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = ['product_id', 'sku', 'price', 'currency', 'status'];

    protected $casts = [
        'price' => 'decimal:2',
        'status' => OrderStatus::class,
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasOne<Delivery, $this> */
    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    /** @return HasMany<PaymentEvent, $this> */
    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    /** @return HasMany<MoneyMovement, $this> */
    public function moneyMovements(): HasMany
    {
        return $this->hasMany(MoneyMovement::class);
    }

    /** @return HasOne<ProductKey, $this> */
    public function productKey(): HasOne
    {
        return $this->hasOne(ProductKey::class);
    }

    public function transitionTo(OrderStatus $newStatus): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Invalid status transition from [{$this->status->value}] to [{$newStatus->value}]"
            );
        }

        $this->update(['status' => $newStatus]);
    }
}
