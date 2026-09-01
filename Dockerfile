# ===================================================
# Stage 1 — build: PHP deps + frontend bundle.
# Node and PHP both live here because the Wayfinder Vite plugin runs
# `artisan` during the build to generate typed route helpers.
# ===================================================
FROM php:8.4-cli AS build

RUN apt-get update && apt-get install -y \
    git curl zip unzip libpq-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql bcmath pcntl intl \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN composer dump-autoload --optimize \
    && npm run build

# ===================================================
# Stage 2 — runtime: PHP only, no Node and no node_modules.
# ===================================================
FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git curl zip unzip libpq-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql bcmath pcntl intl \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .
COPY --from=build /app/vendor ./vendor
COPY --from=build /app/public/build ./public/build
# Wayfinder output: gitignored, generated during the frontend build.
COPY --from=build /app/resources/js/routes ./resources/js/routes
COPY --from=build /app/resources/js/actions ./resources/js/actions
COPY --from=build /app/resources/js/wayfinder ./resources/js/wayfinder

# Railway provides PORT env var, default 8000
ENV PORT=8000

EXPOSE ${PORT}

CMD ["sh", "-c", "php artisan migrate --force && php artisan l5-swagger:generate && php -S 0.0.0.0:$PORT -t public server.php"]
