<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoneyMovement extends Model
{
    protected $table = 'money_movements';

    protected $fillable = ['order_id', 'account', 'entry_group', 'type', 'amount', 'currency'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
