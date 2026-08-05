FROM php:8.4-fpm-alpine

RUN apk add --no-cache postgresql-dev icu-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql intl opcache \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
