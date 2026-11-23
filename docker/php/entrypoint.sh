#!/bin/sh
set -e

cd /var/www/html

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
