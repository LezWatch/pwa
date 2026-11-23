# syntax=docker/dockerfile:1

# --- Composer dependencies (no dev packages) ---------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# --- PHP-FPM application ------------------------------------------------------
FROM php:8.4-fpm-bookworm AS app

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_sqlite opcache

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor

# SQLite lives on a named volume mounted here; Docker copies this directory's
# ownership into a fresh volume, so the database is writable by www-data.
RUN mkdir -p database/data \
    && chown -R www-data:www-data database/data storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/lwtv-entrypoint
RUN chmod +x /usr/local/bin/lwtv-entrypoint

ENTRYPOINT ["lwtv-entrypoint"]
CMD ["php-fpm"]

# --- nginx in front of PHP-FPM ------------------------------------------------
FROM nginx:1.28-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
