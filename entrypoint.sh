#!/bin/sh
set -e

cd /var/www

MODE="${1:-web}"

install_dependencies() {
    if [ ! -f vendor/autoload.php ]; then
        composer install --no-interaction --no-scripts
    fi
}

wait_for_database() {
    if [ -z "$DATABASE_URL" ]; then
        return 0
    fi

    echo "Waiting for database..."
    i=0
    while [ "$i" -lt 30 ]; do
        if php bin/console doctrine:query:sql "SELECT 1" >/dev/null 2>&1; then
            echo "Database is ready."
            return 0
        fi
        i=$((i + 1))
        sleep 2
    done

    echo "Warning: database not reachable after 60s, continuing..."
}

run_migrations() {
    php bin/console doctrine:migrations:migrate --no-interaction --no-ansi
}

warm_symfony_cache() {
    php bin/console cache:clear --env="${APP_ENV:-prod}" --no-warmup
    php bin/console cache:warmup --env="${APP_ENV:-prod}"
}

configure_nginx() {
    if [ -n "$PORT" ]; then
        sed -i "s/listen 80;/listen ${PORT};/" /etc/nginx/conf.d/default.conf
    fi

    sed -i 's/app:9000/127.0.0.1:9000/g' /etc/nginx/conf.d/default.conf
}

case "$MODE" in
    php-fpm)
        install_dependencies
        wait_for_database
        run_migrations
        exec php-fpm -F
        ;;
    web)
        configure_nginx
        install_dependencies
        wait_for_database
        run_migrations
        warm_symfony_cache
        php-fpm -D
        exec nginx -g 'daemon off;'
        ;;
    *)
        exec "$@"
        ;;
esac
