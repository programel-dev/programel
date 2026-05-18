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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/api/php-prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader

COPY api/ .
RUN rm -rf src/Shared/Infrastructure/DataFixtures/ \
    && composer dump-autoload --optimize --classmap-authoritative \
    && rm /usr/bin/composer \
    && echo "APP_ENV=prod" > .env \
    && mkdir -p var/cache var/log \
    && APP_ENV=prod \
       APP_SECRET=placeholder \
       DATABASE_URL="postgresql://localhost:5432/db?serverVersion=16" \
       REDIS_URL="redis://localhost:6379" \
       LOCK_DSN="redis://localhost:6379" \
       MAILER_DSN="null://null" \
       JWT_PASSPHRASE=placeholder \
       JWT_SECRET_KEY=/dev/null \
       JWT_PUBLIC_KEY=/dev/null \
       SENTRY_DSN="" \
       TELEGRAM_BOT_TOKEN=placeholder \
       TELEGRAM_BOT_USERNAME=placeholder \
       TELEGRAM_WEBHOOK_SECRET=placeholder \
       EQUEUE_TARGET_URL="https://placeholder.example" \
       EQUEUE_USER_AGENT=placeholder \
       php bin/console cache:warmup \
    && chown -R www-data:www-data var .env

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD php-fpm-healthcheck || exit 1

USER www-data
