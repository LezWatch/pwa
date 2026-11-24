#!/bin/sh
set -e

# APP_DIR is only overridden by tests/Unit/EntrypointTest.php.
cd "${APP_DIR:-/var/www/html}"

# Locally, compose.override.yaml mounts the host checkout over the image,
# vendor/ included. Without a host `composer install` there is no vendor/,
# and every PHP command below would die with a bare "Failed opening required".
if [ ! -f vendor/autoload.php ]; then
    cat >&2 <<'MSG'
lwtv: vendor/autoload.php is missing, so PHP dependencies aren't installed.

Locally, compose.override.yaml mounts your checkout (including vendor/) into
the container. Install dependencies on your machine, then start again:

    npm run installer
    npm run build

Run `npm run installer` again whenever composer.json or composer.lock changes.
MSG
    exit 1
fi

# Run a command as www-data. The app container starts as root (php-fpm drops
# privileges itself); the scheduler already runs as www-data.
as_www() {
    if [ "$(id -u)" = "0" ]; then
        su -s /bin/sh www-data -c "$1"
    else
        sh -c "$1"
    fi
}

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    db="${DB_DATABASE:-/var/www/html/database/data/database.sqlite}"
    if [ ! -f "$db" ]; then
        as_www "touch '$db'"
    fi
    as_www "php artisan migrate --force"
fi

if [ "${APP_ENV:-production}" != "local" ]; then
    as_www "php artisan config:cache && php artisan route:cache && php artisan view:cache"
fi

exec "$@"
