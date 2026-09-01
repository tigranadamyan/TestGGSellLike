<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    protected $fillable = ['sku', 'name', 'type', 'price', 'currency', 'available_keys_count'];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    /** @return HasMany<ProductKey, $this> */
    public function keys(): HasMany
    {
        return $this->hasMany(ProductKey::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<ProductKey, $this> */
    public function availableKeys(): HasMany
    {
        return $this->keys()->where('status', 'available');
    }
}
