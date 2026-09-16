# Railway builds this directly. It runs the code as submitted, unchanged.
FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev unzip git \
    && docker-php-ext-install pdo pdo_pgsql pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize

# Git does not always carry the executable bit (and neither do some file-sync
# tools), and an entrypoint without it fails the deploy with nothing but
# "permission denied". Set it here so the image cannot be built wrong.
RUN chmod +x /app/docker-entrypoint.sh

ENV APP_ENV=production

# `php artisan serve` wraps PHP's built-in server, which handles ONE request at
# a time unless told otherwise. That is fine for a demo and fatal for the race
# script in the README: twenty "simultaneous" bookings would queue up and prove
# nothing. Workers make them genuinely concurrent, so the exclusion constraint
# is what decides the winner - which is the whole point of the exercise.
ENV PHP_CLI_SERVER_WORKERS=4

EXPOSE 8080

ENTRYPOINT ["/app/docker-entrypoint.sh"]
