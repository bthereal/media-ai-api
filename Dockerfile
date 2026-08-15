FROM composer:2 AS composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
        libpq-dev \
        icu-dev \
        ffmpeg \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        intl \
        opcache

COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY memory.ini /usr/local/etc/php/conf.d/memory.ini

COPY --from=composer /app/vendor /var/www/html/vendor

WORKDIR /var/www/html
