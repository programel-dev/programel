FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
        icu-libs \
        icu-dev \
        libpq \
        libpq-dev \
        libzip \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        intl \
        opcache \
        pdo_pgsql \
        zip \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del icu-dev libpq-dev libzip-dev $PHPIZE_DEPS

WORKDIR /app

# --- Development ---
FROM base AS dev

RUN apk add --no-cache $PHPIZE_DEPS linux-headers \
    && pecl install xdebug && docker-php-ext-enable xdebug

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/api/php-dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

# --- Production ---
FROM base AS prod

COPY docker/api/php-prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader

COPY api/ .
RUN composer dump-autoload --optimize --classmap-authoritative \
    && bin/console cache:warmup --env=prod

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD php-fpm-healthcheck || exit 1

USER www-data
