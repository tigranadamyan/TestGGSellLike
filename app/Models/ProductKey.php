<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductKeyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductKey extends Model
{
    protected $table = 'product_keys';

    protected $fillable = ['product_id', 'code', 'status', 'order_id'];

    protected $casts = [
        'status' => ProductKeyStatus::class,
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
