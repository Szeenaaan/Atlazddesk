# syntax=docker/dockerfile:1
FROM php:8.4-fpm-bookworm AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libonig-dev libfcgi-bin unzip ca-certificates curl \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl mbstring zip bcmath pcntl opcache \
    && rm -rf /var/lib/apt/lists/*
# Keep runtime compilation cached when the upstream extension download is transiently unavailable.
RUN curl --fail --location --retry 3 --retry-all-errors --retry-delay 5 \
    --connect-timeout 15 --max-time 90 https://pecl.php.net/get/redis-6.2.0.tgz -o /tmp/redis.tgz \
    && pecl install /tmp/redis.tgz && docker-php-ext-enable redis \
    && rm /tmp/redis.tgz
WORKDIR /var/www/html
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

FROM php-base AS php-build
COPY backend-overlay/composer.json backend-overlay/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY backend-overlay/ ./
RUN mkdir -p bootstrap/cache storage/app/private storage/app/public storage/logs \
    storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && php artisan package:discover --ansi

FROM php-base AS app
COPY --from=php-build --chown=www-data:www-data /var/www/html /var/www/html
COPY infra/docker/php.ini /usr/local/etc/php/conf.d/atlazddesk.ini
COPY infra/docker/fpm.conf /usr/local/etc/php-fpm.d/zz-atlazddesk.conf
COPY --chmod=755 infra/docker/entrypoint.sh /usr/local/bin/atlazddesk-entrypoint
COPY infra/docker/health.php infra/docker/configure.php /opt/atlazddesk/
USER www-data
ENTRYPOINT ["atlazddesk-entrypoint"]
CMD ["php-fpm", "-F"]
EXPOSE 9000

FROM node:24-bookworm-slim AS frontend-build
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM nginx:1.28-alpine AS web
COPY --from=frontend-build /build/dist /usr/share/nginx/html
COPY infra/docker/nginx.conf.template /etc/nginx/templates/default.conf.template
EXPOSE 80 443
