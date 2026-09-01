<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierRequest extends Model
{
    protected $table = 'supplier_requests';

    protected $fillable = ['request_id', 'supplier', 'sku', 'status', 'code', 'error', 'response_payload'];

    protected $casts = [
        'response_payload' => 'array',
    ];
}
