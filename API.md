# Документация API — Бэкенд магазина цифровых товаров

Базовый URL: `http://localhost:8080/api` (Docker) или `http://localhost:8000/api` (локально)

Content-Type: `application/json` для всех запросов и ответов.

---

## Эндпоинты

### 0. Витрина остатков

```
GET /api/catalog
```

Горячий запрос витрины. Читает только `products`, без join с `product_keys`:
наличие хранится в денормализованном счётчике `available_keys_count`.

**Параметры запроса:**

| Параметр   | Тип    | По умолчанию | Описание |
|------------|--------|--------------|----------|
| `type`     | string | —            | Фильтр по типу (`key`, `giftcard`, `subscription`, `topup`) |
| `in_stock` | bool   | `true`       | Только товары в наличии |
| `per_page` | int    | `24`         | Размер страницы, максимум `100` |
| `after`    | int    | —            | Курсор: `id` последнего товара предыдущей страницы |

**Ответ: `200 OK`**

```json
{
  "data": [
    {
      "sku": "KEY-CS2-PRIME",
      "name": "CS2 Prime Status ключ",
      "type": "key",
      "price": "1290.00",
      "currency": "RUB",
      "in_stock": true,
      "available": 6
    }
  ],
  "meta": { "next_cursor": 4 }
}
```

Пагинация **keyset**, а не `OFFSET`: следующую страницу нужно запрашивать как
`?after=<next_cursor>`. `next_cursor: null` означает, что страниц больше нет.
`OFFSET` пришлось бы прочитать и выбросить все предыдущие строки, поэтому его
стоимость растёт с глубиной страницы, а у keyset она постоянна.

---

### 1. Создание заказа

```
POST /api/orders
```

**Тело запроса:**

| Поле   | Тип    | Обязательно | Описание                                   |
|--------|--------|-------------|--------------------------------------------|
| `sku`  | string | да          | Артикул товара (должен существовать в таблице `products`) |

**Ответ: `201 Created`**

```json
{
  "data": {
    "id": 1,
    "sku": "STEAM_100",
    "price": "1000.00",
    "currency": "RUB",
    "status": "created",
    "created_at": "2025-08-31T12:00:00.000000Z"
  }
}
```

**Ответы об ошибках:**

- `422 Unprocessable Entity` — ошибка валидации (отсутствует `sku`, SKU не найден)
- `404 Not Found` — товар с указанным SKU не найден

**Поток статусов:** Заказ создаётся со статусом `created`. Ожидает оплаты.

---

### 2. Получение заказа

```
GET /api/orders/{id}
```

**Параметры пути:**

| Параметр | Тип | Описание |
|----------|-----|----------|
| `id`     | int | ID заказа |

**Ответ: `200 OK`**

```json
{
  "data": {
    "id": 1,
    "sku": "STEAM_100",
    "price": "1000.00",
    "currency": "RUB",
    "status": "delivered",
    "delivery": {
      "status": "completed",
      "code": "SA-XXXXXXXX",
      "supplier": "supplier_a"
    },
    "created_at": "2025-08-31T12:00:00.000000Z"
  }
}
```

**Примечания:**
- `delivery` равен `null`, если запись о доставке ещё не создана (статус `created` или `payment_failed`)
- `delivery.code` — доставленный цифровой ключ

**Ответы об ошибках:**

- `404 Not Found` — заказ не найден

---

### 3. Вебхук оплаты

```
POST /api/webhooks/payment
```

Этот эндпоинт вызывается платёжным шлюзом для уведомления о статусе оплаты.

**Тело запроса:**

| Поле         | Тип    | Обязательно | Описание                                                              |
|--------------|--------|-------------|-----------------------------------------------------------------------|
| `event_id`   | string | да          | Уникальный идентификатор платёжного события (ключ идемпотентности)     |
| `order_id`   | int    | да          | ID заказа для оплаты                                                  |
| `status`     | string | да          | `paid` или `failed`                                                   |
| `amount`     | number | да          | Сумма платежа                                                         |
| `currency`   | string | да          | Код валюты ISO 4217 (3 символа, например `RUB`)                       |
| `created_at` | string | да          | Временная метка события                                               |

**Ответ: `200 OK`**

```json
{
  "status": "ok",
  "outcome": "applied",
  "duplicate": false
}
```

| `outcome`       | Значение |
|-----------------|----------|
| `applied`       | Событие применено, заказ сменил состояние |
| `duplicate`     | Тот же `event_id` уже приходил (at-least-once), побочных действий нет |
| `pending_order` | Событие принято и сохранено, но заказа с таким id ещё нет — будет применено сверкой |
| `ignored`       | Событие корректно, но заказ уже вышел из состояния `created` |

`duplicate` сохранён для обратной совместимости и равен `outcome == "duplicate"`.

**Логика работы:**
1. Блокировка строки заказа (`FOR UPDATE`), если он существует
2. Вставка строки в `payment_events` с ограничением UNIQUE на `event_id`
3. Если дубликат → `outcome: "duplicate"`, побочных действий нет
4. Если заказа ещё нет → событие сохраняется с `order_id = NULL` и
   `claimed_order_id = <id>`, `outcome: "pending_order"`
5. Если статус `paid` → переход `created → paid`, запись в `money_movements`,
   диспатч `DeliverProductJob`
6. Если статус `failed` → переход `created → payment_failed`

**Коды ответа:**

- `200 OK` — событие принято (во всех перечисленных выше исходах)
- `422 Unprocessable Entity` — только структурная ошибка валидации тела запроса

> Контракт вебхука трактует `2xx` как «принято», а `5xx` как «повторить доставку».
> Поэтому несуществующий `order_id` **не** отклоняется через `422`: это сказало бы
> платёжной системе «доставлено, не повторять», и платёж был бы потерян. Событие
> сохраняется и применяется, как только заказ появится (`php artisan orders:reconcile`).

---

### 4. Сверка (внутренний)

```
POST /api/internal/reconciliation
```

Запускает процесс сверки/восстановления. Находит застрявшие заказы и повторяет попытки доставки.

**Ответ: `200 OK`**

```json
{
  "data": {
    "paid_not_delivered": [1, 3],
    "delivered_not_paid": [],
    "stale_delivering": [5],
    "out_of_stock": [7],
    "delivery_failed": [9],
    "recovered": 4
  }
}
```

| Поле | Описание |
|------|----------|
| `paid_not_delivered` | ID заказов, оплаченных, но без завершённой доставки → повторный диспатч |
| `delivered_not_paid` | ID заказов, доставленных, но без платёжного события `paid` → аномалия |
| `stale_delivering` | ID заказов, застрявших в статусе `delivering` >5 мин → повторный диспатч |
| `out_of_stock` | ID заказов со статусом `out_of_stock` → повторная проверка наличия ключей |
| `delivery_failed` | ID заказов со статусом `delivery_failed` → повторная попытка |
| `recovered` | Общее количество заказов, для которых предпринята попытка восстановления |

**Также доступна через CLI:**

```bash
php artisan orders:reconcile
```

Запускается автоматически каждые 5 минут через планировщик.

---

## Конечный автомат статусов заказа

```
created ──→ paid ──→ delivering ──→ delivered
   │                           │
   └──→ payment_failed         ├──→ out_of_stock ──→ delivering (повтор)
                               └──→ delivery_failed ──→ delivering (повтор)
```

| Откуда | Допустимые переходы |
|--------|-------------------|
| `created` | `paid`, `payment_failed` |
| `paid` | `delivering` |
| `delivering` | `delivered`, `out_of_stock`, `delivery_failed` |
| `delivered` | _(терминальный)_ |
| `payment_failed` | _(терминальный)_ |
| `out_of_stock` | `delivering` |
| `delivery_failed` | `delivering` |

Недопустимые переходы выбрасывают `InvalidArgumentException`.

---

## Схема базы данных

### `products`
| Столбец    | Тип           | Ограничения    |
|------------|---------------|----------------|
| `id`       | bigint        | PK, автоинкремент |
| `sku`      | varchar       | **UNIQUE**     |
| `name`     | varchar       |                |
| `type`     | varchar       |                |
| `price`    | numeric(12,2) |                |
| `currency` | varchar(3)    | по умолчанию `RUB` |
| `available_keys_count` | integer | денормализованный остаток; частичные индексы `(type, id)` и `(id)` `WHERE available_keys_count > 0` |

### `orders`
| Столбец     | Тип           | Ограничения              |
|-------------|---------------|--------------------------|
| `id`        | bigint        | PK, автоинкремент        |
| `product_id`| bigint        | FK → products            |
| `sku`       | varchar       |                          |
| `price`     | numeric(12,2) |                          |
| `currency`  | varchar(3)    | по умолчанию `RUB`       |
| `status`    | varchar       | по умолчанию `created`, INDEX |

### `payment_events`
| Столбец       | Тип           | Ограничения       |
|---------------|---------------|-------------------|
| `id`          | bigint        | PK, автоинкремент |
| `event_id`    | varchar       | **UNIQUE** — ключ идемпотентности |
| `order_id`    | bigint        | FK → orders, nullable (NULL, пока заказа нет) |
| `claimed_order_id` | bigint   | id заказа со слов платёжной системы; INDEX(`claimed_order_id`, `processed_at`) |
| `status`      | varchar       |                   |
| `amount`      | numeric(12,2) |                   |
| `currency`    | varchar(3)    |                   |
| `payload`     | jsonb         | nullable          |
| `created_at`  | timestamp     |                   |
| `processed_at`| timestamp     | nullable          |

### `product_keys`
| Столбец     | Тип     | Ограничения                                |
|-------------|---------|--------------------------------------------|
| `id`        | bigint  | PK, автоинкремент                          |
| `product_id`| bigint  | FK → products                              |
| `code`      | varchar | **UNIQUE**                                 |
| `status`    | varchar | по умолчанию `available`, INDEX(product_id, status) |
| `order_id`  | bigint  | FK → orders, nullable, **UNIQUE** — заказ расходует максимум один ключ |

### `deliveries`
| Столбец     | Тип     | Ограничения                    |
|-------------|---------|--------------------------------|
| `id`        | bigint  | PK, автоинкремент              |
| `order_id`  | bigint  | **UNIQUE**, FK → orders        |
| `request_id`| varchar | **UNIQUE**                     |
| `supplier`  | varchar |                                |
| `status`    | varchar | по умолчанию `pending`         |
| `code`      | varchar | nullable                       |
| `attempts`  | int     | по умолчанию 0                 |
| `last_error`| text    | nullable                       |

### `supplier_requests`
| Столбец          | Тип     | Ограничения       |
|------------------|---------|-------------------|
| `id`             | bigint  | PK, автоинкремент |
| `request_id`     | varchar | **UNIQUE**        |
| `supplier`       | varchar |                   |
| `sku`            | varchar |                   |
| `status`         | varchar | по умолчанию `pending` |
| `code`           | varchar | nullable          |
| `error`          | text    | nullable          |
| `response_payload`| jsonb  | nullable          |

### `money_movements` (двойная запись)
| Столбец       | Тип           | Ограничения                                   |
|---------------|---------------|-----------------------------------------------|
| `id`          | bigint        | PK, автоинкремент                             |
| `order_id`    | bigint        | FK → orders                                   |
| `account`     | varchar       | `cash` \| `deferred_revenue` \| `revenue`, INDEX |
| `entry_group` | uuid          | INDEX — группа сбалансированной проводки       |
| `type`        | varchar       | `payment_received` \| `revenue_recognised` \| `refund` |
| `amount`      | numeric(12,2) | знаковая сумма                                 |
| `currency`    | varchar(3)    |                                               |

Только на добавление. Сумма `amount` внутри одного `entry_group` всегда равна нулю,
поэтому и весь журнал всегда равен нулю.

---

## Exactly-Once доставка: 6 уровней защиты

| Уровень | Механизм | Что предотвращает |
|---------|----------|-------------------|
| 1. `payment_events.event_id` UNIQUE | БД отклоняет дублирующие вставки `event_id` | Двойная обработка платежа |
| 2. `Order::lockForUpdate()` | Построчная блокировка внутри транзакции | Конкурентные платежи для одного заказа |
| 3. `deliveries.order_id` UNIQUE | БД отклоняет дублирующие записи доставки | Две доставки для одного заказа |
| 4. Условный `UPDATE ... WHERE status IN (...)` | «Захват» заказа удаётся ровно одному воркеру | Параллельная выдача по одному заказу |
| 5. `product_keys.order_id` UNIQUE + `SKIP LOCKED` | Атомарный резерв ключа с жёстким ограничением | Расход нескольких ключей на один заказ |
| 6. `supplier_requests.request_id` UNIQUE | Поставщик возвращает сохранённый результат при повторе | Двойная выдача от поставщика |

Уровень 4 — основной: `SKIP LOCKED` сам по себе **помогает** параллельным воркерам
взять *разные* ключи, поэтому без захвата восемь воркеров по одному заказу
израсходовали бы восемь ключей.

---

## Семантика таймаута

**Таймаут ≠ Отказ.** Когда запрос к поставщику завершается по таймауту, исход неизвестен.

1. **Первый вызов** завершается таймаутом → поставщик сохраняет результат в `supplier_requests`, возвращает таймаут
2. **Повтор** с тем же `request_id` → поставщик находит существующую запись, возвращает сохранённый результат
3. **Никогда не генерируйте новый `request_id`** для повторов после таймаута

Это гарантирует: даже если поставщик реально выдал ключ во время таймаута, повтор безопасно его получит.

---

## Схема фоллбэка поставщиков

```
1. Пробуем Supplier A
   ├── успех → готово
   ├── таймаут → повтор A (тот же request_id)
   │   ├── успех → готово
   │   └── исчерпаны попытки → пробуем Supplier B
   └── однозначная ошибка → пробуем Supplier B
2. Пробуем Supplier B
   ├── успех → готово
   ├── таймаут → повтор B (тот же request_id)
   └── все попытки исчерпаны → delivery_failed
```

- Формат `request_id`: `req_{order_id}_{supplier_key}` (например, `req_42_a`)
- Каждый поставщик хранит результаты независимо в `supplier_requests`

---

## Порты Docker

| Сервис     | Внутренний порт | Порт хоста |
|------------|----------------|------------|
| App (PHP)  | 8000           | **8080**   |
| PostgreSQL | 5432           | **5433**   |
| Redis      | 6379           | **6380**   |

---

## Переменные окружения

### Конфигурация поставщиков

```env
SUPPLIER_A_FAILURE_RATE=0      # 0.0–1.0 (вероятимость симулированного отказа)
SUPPLIER_A_TIMEOUT_RATE=0      # 0.0–1.0 (вероятимость симулированного таймаута)
SUPPLIER_A_DELAY_MS=0          # задержка ответа в мс
SUPPLIER_B_FAILURE_RATE=0
SUPPLIER_B_TIMEOUT_RATE=0
SUPPLIER_B_DELAY_MS=0
SUPPLIER_HTTP_TIMEOUT=5        # HTTP-таймаут в секундах (не используется мок-поставщиками)
SUPPLIER_MAX_RETRIES=3         # максимальное количество повторных попыток на поставщика
```

### Очередь

```env
QUEUE_CONNECTION=redis
```

---

## Запуск тестов

```bash
# Все пользовательские тесты (34 теста, 82 утверждения)
php artisan test --filter="OrderCreationTest|PaymentWebhookTest|ConcurrentWebhooksTest|IdempotencyTest|DatabaseConstraintsTest|OrderStatusTransitionTest|OutOfOrderWebhookTest|SupplierTimeoutTest|SupplierFallbackTest|OutOfStockTest|DeliveryJobRetryTest|RecoveryTest"

# Отдельные критические тесты
php artisan test --filter="ConcurrentWebhooksTest::test_fifty_rapid_payment_inserts_only_one_succeeds_via_unique_constraint"
php artisan test --filter="PaymentWebhookTest::test_ignores_duplicate_event_id"
php artisan test --filter="SupplierTimeoutTest::test_supplier_returns_stored_result_on_retry_after_timeout"
php artisan test --filter="SupplierFallbackTest::test_falls_back_to_supplier_b_when_a_fails"
php artisan test --filter="DeliveryJobRetryTest::test_delivery_job_is_idempotent"
php artisan test --filter="RecoveryTest"
php artisan test --filter="DatabaseConstraintsTest"
```
