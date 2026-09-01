<?php

declare(strict_types=1);

return [
    'a' => [
        'name' => 'supplier_a',
        'base_url' => env('SUPPLIER_A_BASE_URL', 'http://mock-supplier-a.test'),
        'failure_rate' => (float) env('SUPPLIER_A_FAILURE_RATE', 0),
        'timeout_rate' => (float) env('SUPPLIER_A_TIMEOUT_RATE', 0),
        'delay_ms' => (int) env('SUPPLIER_A_DELAY_MS', 0),
        'out_of_stock_rate' => (float) env('SUPPLIER_A_OUT_OF_STOCK_RATE', 0),
    ],

    'b' => [
        'name' => 'supplier_b',
        'base_url' => env('SUPPLIER_B_BASE_URL', 'http://mock-supplier-b.test'),
        'failure_rate' => (float) env('SUPPLIER_B_FAILURE_RATE', 0),
        'timeout_rate' => (float) env('SUPPLIER_B_TIMEOUT_RATE', 0),
        'delay_ms' => (int) env('SUPPLIER_B_DELAY_MS', 0),
        'out_of_stock_rate' => (float) env('SUPPLIER_B_OUT_OF_STOCK_RATE', 0),
    ],

    'http_timeout' => (int) env('SUPPLIER_HTTP_TIMEOUT', 5),

    'max_retries' => (int) env('SUPPLIER_MAX_RETRIES', 3),

    'retry_backoff_ms' => [100, 250, 500],
];
