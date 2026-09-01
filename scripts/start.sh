#!/bin/sh
set -e

# ===================================================
# Digital Goods Store — запуск полного стека в Docker
#   ./scripts/start.sh          # app + postgres + redis + horizon
#   ./scripts/start.sh --tunnel # то же + публичный Cloudflare-туннель
# ===================================================

cd "$(dirname "$0")/.."

TUNNEL=0
[ "$1" = "--tunnel" ] && TUNNEL=1

if ! docker info >/dev/null 2>&1; then
    echo "❌ Docker не запущен"
    exit 1
fi

if [ "$TUNNEL" = "1" ]; then
    echo "🔄 Запуск стека + туннеля..."
    docker compose --profile tunnel up -d --build
else
    echo "🔄 Запуск стека..."
    docker compose up -d --build
fi

echo "⏳ Ожидание приложения..."
i=0
until curl -sf -o /dev/null http://localhost:8080/up; do
    i=$((i + 1))
    if [ "$i" -gt 60 ]; then
        echo "❌ Приложение не поднялось. Логи: docker compose logs app"
        exit 1
    fi
    sleep 2
done

echo ""
echo "✅ Стек запущен:"
echo "   App:        http://localhost:8080"
echo "   PostgreSQL: localhost:5433 (dgs_user / dgs_secret / digital_goods)"
echo "   Redis:      localhost:6380"
echo "   Horizon:    http://localhost:8080/horizon"

if [ "$TUNNEL" = "1" ]; then
    echo ""
    echo "⏳ Ожидание публичного адреса..."
    i=0
    URL=""
    while [ -z "$URL" ]; do
        URL=$(docker logs dgs_tunnel 2>&1 \
              | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' \
              | head -1)
        i=$((i + 1))
        if [ "$i" -gt 30 ]; then
            echo "❌ Не удалось получить адрес. Логи: docker logs dgs_tunnel"
            exit 1
        fi
        [ -z "$URL" ] && sleep 2
    done
    echo ""
    echo "🌍 Публичный адрес: $URL"
    echo "   Ссылка временная и открыта всем, у кого она есть."
    echo "   При перезапуске туннеля адрес меняется."
fi

echo ""
echo "Остановить: docker compose --profile tunnel down"
