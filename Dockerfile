FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpq-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql bcmath pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

COPY . .

RUN composer dump-autoload --optimize

# Railway provides PORT env var, default 8000
ENV PORT=8000

EXPOSE ${PORT}

CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=$PORT"]
