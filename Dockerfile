FROM dunglas/frankenphp:1-php8.4-alpine AS base

WORKDIR /app

RUN apk add --no-cache \
    bash \
    icu-dev \
    libpq-dev \
    nodejs \
    npm \
    postgresql-client \
    tzdata \
    && install-php-extensions \
    bcmath \
    intl \
    opcache \
    pcntl \
    pdo_pgsql \
    redis \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock package.json package-lock.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && npm ci --ignore-scripts

COPY . .

RUN npm run build \
    && composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

CMD ["frankenphp", "php-server", "-r", "public", "--listen", ":8000"]
