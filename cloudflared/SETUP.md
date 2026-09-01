# Публичный доступ к Digital Goods Store

Весь стек (Laravel + PostgreSQL + Redis + Horizon + туннель) поднимается в Docker
одной командой. Наружу сайт отдаётся через Cloudflare Tunnel.

---

## Быстрый старт (без домена и без авторизации)

```bash
./scripts/start.sh --tunnel
```

Скрипт соберёт образы, поднимет стек, дождётся `/up` и напечатает публичный адрес
вида `https://<случайные-слова>.trycloudflare.com`.

То же вручную:

```bash
docker compose --profile tunnel up -d --build
docker logs dgs_tunnel | grep trycloudflare.com
```

Без туннеля (только локально, на http://localhost:8080):

```bash
docker compose up -d --build
```

Остановить всё:

```bash
docker compose --profile tunnel down
```

### Что важно знать про quick tunnel

- **Не нужен** ни аккаунт Cloudflare, ни домен, ни `cloudflared tunnel login`,
  ни `cert.pem` — поэтому здесь нет шагов с авторизацией.
- Адрес **временный**: при каждом перезапуске контейнера `dgs_tunnel` он меняется.
- Ссылка **публичная** — сайт видит любой, у кого она есть. Для демо это нормально,
  для постоянного стенда — см. следующий раздел.

---

## Постоянный адрес на своём домене (named tunnel)

Нужен аккаунт Cloudflare с добавленным доменом.

1. Логин (создаёт `~/.cloudflared/cert.pem`):

   ```bash
   cloudflared tunnel login
   ```

2. Создать туннель и DNS-запись:

   ```bash
   cloudflared tunnel create dgs-tunnel
   cloudflared tunnel route dns dgs-tunnel dgs.example.com
   ```

3. Взять токен туннеля в Cloudflare Zero Trust → Networks → Tunnels и заменить
   в `docker-compose.yml` команду сервиса `tunnel` на:

   ```yaml
   environment:
     - TUNNEL_TOKEN=${CLOUDFLARE_TUNNEL_TOKEN}
   command: tunnel --no-autoupdate run
   ```

4. Положить токен в окружение и поднять стек:

   ```bash
   export CLOUDFLARE_TUNNEL_TOKEN=<токен>
   docker compose --profile tunnel up -d
   ```

Файл `cloudflared/config.yml` относится к этому же сценарию (запуск cloudflared
не по токену, а по конфигу с `credentials-file`).

---

## Состав стека

| Сервис         | Контейнер      | Порт наружу | Примечание                          |
|----------------|----------------|-------------|-------------------------------------|
| Laravel (app)  | `dgs_app`      | 8080 → 8000 | `php -S` + `server.php`, миграции на старте |
| PostgreSQL 16  | `dgs_postgres` | 5433 → 5432 | `dgs_user` / `dgs_secret`           |
| Redis 7        | `dgs_redis`    | 6380 → 6379 | cache + session + queue             |
| Horizon        | `dgs_horizon`  | —           | обработчик очередей                 |
| cloudflared    | `dgs_tunnel`   | —           | профиль `tunnel`                    |

## Что открыто в приложении

| Адрес                | Что это                                   |
|----------------------|-------------------------------------------|
| `/`                  | Витрина: каталог из живого API            |
| `/api/documentation` | Swagger UI по OpenAPI-спецификации        |
| `/docs`              | Сама спецификация в JSON                  |
| `/horizon`           | Панель очередей                           |
| `/up`                | Health-check                              |

Спецификация генерируется из PHP-атрибутов на контроллерах командой
`php artisan l5-swagger:generate` — она выполняется при старте контейнера `app`,
так что после правки атрибутов достаточно перезапустить сервис.

Все настройки приложения (`APP_KEY`, `DB_*`, `REDIS_*`) заданы прямо в
`docker-compose.yml` через якорь `x-app-env` — файл `.env` в контейнер не
копируется (он в `.dockerignore`), а переменные окружения имеют приоритет
над `.env` при бинд-маунте.

Контейнер отдаёт HTTP через `php -S … server.php`, а не через `php artisan serve`.
Это важно: `serve` прокидывает в процесс, обслуживающий запросы, только
переменные из белого списка (`ServeCommand::$passthroughVariables`), и `DB_*`
до него не доходят — приложение молча уходит на `.env`, то есть на sqlite.

## Частые команды

```bash
docker compose logs -f app          # логи приложения
docker compose exec app php artisan migrate:fresh --seed
docker compose exec postgres psql -U dgs_user -d digital_goods
```
