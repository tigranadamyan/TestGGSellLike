#!/bin/sh
set -e

# ===================================================
# Cloudflare Tunnel — автоматическая настройка
# Запуск: ./scripts/setup-tunnel.sh
# ===================================================

ENV_FILE=".env"
TUNNEL_NAME="dgs-tunnel"
CONFIG_DIR="$HOME/.cloudflared"

echo "╔══════════════════════════════════════════╗"
echo "║  Cloudflare Tunnel — Auto Setup          ║"
echo "║  Digital Goods Store                     ║"
echo "╚══════════════════════════════════════════╝"
echo ""

# ---- Проверки ----

if ! command -v cloudflared &> /dev/null; then
    echo "❌ cloudflared не установлен"
    echo "   brew install cloudflared"
    exit 1
fi
echo "✅ cloudflared $(cloudflared --version 2>&1 | head -1 | awk '{print $3}')"

if [ ! -f "$CONFIG_DIR/cert.pem" ]; then
    echo ""
    echo "🔑 Нужна авторизация в Cloudflare."
    echo "   Откроется браузер — выбери домен и нажми Authorize."
    echo ""
    cloudflared tunnel login
    echo ""
    echo "✅ Авторизация прошла успешно."
fi
echo "✅ Сертификат найден: $CONFIG_DIR/cert.pem"

# ---- Шаг 1: Домен ----

echo ""
read -p "🌐 Введи свой домен (например example.com): " CF_DOMAIN

if [ -z "$CF_DOMAIN" ]; then
    echo "❌ Домен не может быть пустым"
    exit 1
fi

SUBDOMAIN="dgs"
FULL_DOMAIN="${SUBDOMAIN}.${CF_DOMAIN}"
echo "   Будет создан: https://${FULL_DOMAIN}"

# ---- Шаг 2: Создание туннеля ----

echo ""
echo "🔄 Проверяю существующие туннели..."

EXISTING_TUNNEL=$(cloudflared tunnel list 2>/dev/null | grep "$TUNNEL_NAME" | awk '{print $1}' || true)

if [ -n "$EXISTING_TUNNEL" ]; then
    echo "✅ Туннель '$TUNNEL_NAME' уже существует (ID: $EXISTING_TUNNEL)"
    TUNNEL_ID="$EXISTING_TUNNEL"
else
    echo "🔄 Создаю туннель '$TUNNEL_NAME'..."
    TUNNEL_ID=$(cloudflared tunnel create "$TUNNEL_NAME" 2>&1 | grep -oP 'Created cloudflared tunnel \K[a-f0-9-]+' || true)

    if [ -z "$TUNNEL_ID" ]; then
        # Fallback: извлекаем ID из вывода
        TUNNEL_ID=$(cloudflared tunnel list | grep "$TUNNEL_NAME" | awk '{print $1}')
    fi

    if [ -z "$TUNNEL_ID" ]; then
        echo "❌ Не удалось создать туннель. Вывод:"
        cloudflared tunnel create "$TUNNEL_NAME" 2>&1
        exit 1
    fi
    echo "✅ Туннель создан: $TUNNEL_ID"
fi

# ---- Шаг 3: DNS маршрут ----

echo ""
echo "🔄 Настраиваю DNS-маршрут: ${FULL_DOMAIN} → туннель..."

cloudflared tunnel route dns "$TUNNEL_ID" "$FULL_DOMAIN" 2>&1 || {
    echo "⚠️  DNS-маршрут уже существует или не удалось создать (это нормально)."
}

echo "✅ DNS настроен: ${FULL_DOMAIN} → ${TUNNEL_ID}"

# ---- Шаг 4: Токен для Docker ----

echo ""
echo "🔄 Получаю токен туннеля для Docker..."

TUNNEL_TOKEN=$(cloudflared tunnel token "$TUNNEL_ID" 2>/dev/null || true)

if [ -z "$TUNNEL_TOKEN" ]; then
    echo "⚠️  Не удалось автоматически получить токен."
    echo "   Создай токен вручную:"
    echo "   1. Зайди в https://one.dash.cloudflare.com"
    echo "   2. Networks → Tunnels → '$TUNNEL_NAME' → Configure"
    echo "   3. скопируй токен из Docker-инструкции"
    echo ""
    read -p "📝 Вставь токен (или нажми Enter чтобы пропустить): " TUNNEL_TOKEN
fi

# ---- Шаг 5: Запись в .env ----

echo ""
echo "🔄 Обновляю .env..."

# Удаляем старые значения если есть
if [ -f "$ENV_FILE" ]; then
    # Удаляем строки CLOUDFLARE_TUNNEL_TOKEN и CF_DOMAIN
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' '/^CLOUDFLARE_TUNNEL_TOKEN=/d' "$ENV_FILE"
        sed -i '' '/^CF_DOMAIN=/d' "$ENV_FILE"
    else
        sed -i '/^CLOUDFLARE_TUNNEL_TOKEN=/d' "$ENV_FILE"
        sed -i '/^CF_DOMAIN=/d' "$ENV_FILE"
    fi
fi

# Добавляем новые значения
{
    echo ""
    echo "# Cloudflare Tunnel (auto-generated)"
    echo "CLOUDFLARE_TUNNEL_TOKEN=${TUNNEL_TOKEN}"
    echo "CF_DOMAIN=${CF_DOMAIN}"
} >> "$ENV_FILE"

echo "✅ .env обновлён:"
echo "   CLOUDFLARE_TUNNEL_TOKEN=${TUNNEL_TOKEN:+установлен}${TUNNEL_TOKEN:-пусто}"
echo "   CF_DOMAIN=${CF_DOMAIN}"

# ---- Шаг 6: Обновление config.yml ----

echo ""
echo "🔄 Обновляю cloudflared/config.yml..."

CONFIG_FILE="cloudflared/config.yml"
mkdir -p "$(dirname "$CONFIG_FILE")"

cat > "$CONFIG_FILE" << EOF
tunnel: ${TUNNEL_ID}
credentials-file: ${CONFIG_DIR}/${TUNNEL_ID}.json

ingress:
  - hostname: ${FULL_DOMAIN}
    service: http://localhost:8080
    originRequest:
      noTLSVerify: true
  - service: http_status:404
EOF

echo "✅ config.yml обновлён"

# ---- Шаг 7: docker-compose.tunnel.yml ----

echo ""
echo "🔄 Обновляю docker-compose.tunnel.yml..."

cat > "docker-compose.tunnel.yml" << 'COMPOSE_EOF'
# ===================================================
# Docker Compose — полный стек с Cloudflare Tunnel
# Использование: docker compose -f docker-compose.tunnel.yml up -d
# ===================================================

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: dgs_app
    working_dir: /var/www/html
    depends_on:
      - postgres
      - redis
    environment:
      - DB_HOST=postgres
      - DB_PORT=5432
      - DB_DATABASE=digital_goods
      - DB_USERNAME=dgs_user
      - DB_PASSWORD=dgs_secret
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - QUEUE_CONNECTION=redis
      - CACHE_STORE=redis
      - SESSION_DRIVER=redis
    networks:
      - dgs

  postgres:
    image: postgres:16-alpine
    container_name: dgs_postgres
    environment:
      POSTGRES_DB: digital_goods
      POSTGRES_USER: dgs_user
      POSTGRES_PASSWORD: dgs_secret
    volumes:
      - pgdata:/var/lib/postgresql/data
    networks:
      - dgs

  redis:
    image: redis:7-alpine
    container_name: dgs_redis
    networks:
      - dgs

  horizon:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: dgs_horizon
    working_dir: /var/www/html
    depends_on:
      - postgres
      - redis
      - app
    environment:
      - DB_HOST=postgres
      - DB_PORT=5432
      - DB_DATABASE=digital_goods
      - DB_USERNAME=dgs_user
      - DB_PASSWORD=dgs_secret
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - QUEUE_CONNECTION=redis
    command: php artisan horizon
    networks:
      - dgs

  tunnel:
    image: cloudflare/cloudflared:latest
    container_name: dgs_tunnel
    depends_on:
      - app
    environment:
      - TUNNEL_TOKEN=${CLOUDFLARE_TUNNEL_TOKEN}
    command: tunnel --no-autoupdate run
    networks:
      - dgs
    restart: unless-stopped

volumes:
  pgdata:

networks:
  dgs:
COMPOSE_EOF

echo "✅ docker-compose.tunnel.yml обновлён"

# ---- Готово ----

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║  ✅ Настройка завершена!                 ║"
echo "╚══════════════════════════════════════════╝"
echo ""
echo "📋 Сводка:"
echo "   Туннель:  ${TUNNEL_NAME} (${TUNNEL_ID})"
echo "   Домен:    https://${FULL_DOMAIN}"
echo "   .env:     CLOUDFLARE_TUNNEL_TOKEN и CF_DOMAIN записаны"
echo ""
echo "🚀 Запуск:"
echo ""
echo "   # Вариант 1: Docker (рекомендуется)"
echo "   docker compose -f docker-compose.tunnel.yml up -d"
echo "   docker compose -f docker-compose.tunnel.yml exec app php artisan migrate --seed"
echo ""
echo "   # Вариант 2: Локально + tunnel отдельно"
echo "   docker compose up -d postgres redis app horizon"
echo "   cloudflared tunnel run ${TUNNEL_NAME}"
echo ""
echo "   # Вариант 3: Всё локально"
echo "   docker compose up -d postgres redis"
echo "   php artisan migrate --seed"
echo "   php artisan serve --port=8080 &"
echo "   php artisan horizon &"
echo "   cloudflared tunnel run ${TUNNEL_NAME}"
echo ""
echo "🔍 Проверка:"
echo "   curl https://${FULL_DOMAIN}/up"
echo ""
