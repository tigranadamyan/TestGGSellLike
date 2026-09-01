<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Root of the generated OpenAPI document: metadata, servers, tags and every
 * reusable schema. Endpoint-level attributes live on the controllers so an
 * operation and its documentation change together.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'Digital Goods Store API',
    description: <<<'MD'
    Продажа и выдача цифровых ключей.

    Контракт держится на трёх свойствах:

    - **Идемпотентность вебхука.** `POST /api/webhooks/payment` дедуплицируется по
      `event_id`, поэтому повторная доставка того же события безопасна.
    - **Ровно одна выдача ключа.** Ключ переходит в `issued` под блокировкой строки,
      так что параллельные заказы не могут получить один и тот же ключ.
    - **5xx означает «повтори».** Принятый вебхук всегда получает `200` — даже если
      заказа ещё нет (`outcome: pending_order`) или событие дубль. Ошибка 5xx
      возвращается только когда повтор действительно нужен.

    Аутентификации нет: сервис рассчитан на закрытый периметр, а
    `/api/internal/*` не должен быть доступен извне.
    MD,
)]
#[OA\Server(url: '/', description: 'Текущий хост')]
#[OA\Tag(name: 'Catalog', description: 'Витрина: список товаров в наличии')]
#[OA\Tag(name: 'Orders', description: 'Создание заказа и его статус')]
#[OA\Tag(name: 'Payments', description: 'Вебхук платёжной системы')]
#[OA\Tag(name: 'Internal', description: 'Служебные операции, наружу не публикуются')]
#[OA\Schema(
    schema: 'CatalogItem',
    title: 'CatalogItem',
    description: 'Позиция витрины. Отдаётся без JOIN — `available` берётся из денормализованного счётчика.',
    required: ['sku', 'name', 'type', 'price', 'currency', 'in_stock', 'available'],
    properties: [
        new OA\Property(property: 'sku', type: 'string', example: 'KEY-CS2-PRIME'),
        new OA\Property(property: 'name', type: 'string', example: 'CS2 Prime Status ключ'),
        new OA\Property(property: 'type', type: 'string', enum: ['key', 'giftcard', 'subscription', 'topup'], example: 'key'),
        new OA\Property(
            property: 'price',
            description: 'Строка, а не число: `decimal:2`, чтобы не терять точность.',
            type: 'string',
            example: '1290.00',
        ),
        new OA\Property(property: 'currency', type: 'string', maxLength: 3, minLength: 3, example: 'RUB'),
        new OA\Property(property: 'in_stock', type: 'boolean', example: true),
        new OA\Property(property: 'available', description: 'Число свободных ключей.', type: 'integer', example: 5),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Delivery',
    title: 'Delivery',
    required: ['status', 'code', 'supplier'],
    properties: [
        new OA\Property(
            property: 'status',
            type: 'string',
            enum: ['pending', 'in_progress', 'completed', 'failed'],
            example: 'completed',
        ),
        new OA\Property(
            property: 'code',
            description: 'Сам ключ. Заполнен только при `status: completed`.',
            type: 'string',
            example: 'LFXC-TNCS-BPCD',
            nullable: true,
        ),
        new OA\Property(property: 'supplier', type: 'string', example: 'primary', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Order',
    title: 'Order',
    required: ['id', 'sku', 'price', 'currency', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 2),
        new OA\Property(property: 'sku', type: 'string', example: 'KEY-CS2-PRIME'),
        new OA\Property(property: 'price', type: 'string', example: '1290.00'),
        new OA\Property(property: 'currency', type: 'string', example: 'RUB'),
        new OA\Property(
            property: 'status',
            description: <<<'MD'
            Жизненный цикл: `created` → `paid` → `delivering` → `delivered`.
            Тупиковые состояния: `payment_failed`, `out_of_stock`, `delivery_failed`
            — их подбирает сверка (`/api/internal/reconciliation`).
            MD,
            type: 'string',
            enum: ['created', 'paid', 'delivering', 'delivered', 'payment_failed', 'out_of_stock', 'delivery_failed'],
            example: 'delivered',
        ),
        new OA\Property(property: 'delivery', ref: '#/components/schemas/Delivery', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ValidationError',
    title: 'ValidationError',
    description: 'Стандартный ответ Laravel на непрошедшую валидацию.',
    required: ['message', 'errors'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The selected sku is invalid.'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
        ),
    ],
    type: 'object',
)]
final class ApiDefinition {}
