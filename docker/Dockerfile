FROM php:8.4-cli-alpine

RUN apk add --no-cache unzip

# Required for doctrine/dbal MySQL access.
RUN docker-php-ext-install pdo_mysql

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock /app/

RUN composer install --no-interaction --no-progress --no-dev

COPY src /app/src
COPY bin /app/bin
