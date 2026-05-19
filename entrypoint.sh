#!/bin/sh
set -e

cd /var/www

MODE="${1:-web}"

install_dependencies() {
    if [ ! -f vendor/autoload.php ]; then
        composer install --no-interaction --no-scripts
    fi
}

bootstrap_env() {
    php docker/bootstrap-env.php
}

wait_for_database() {
    echo "Waiting for database..."
    i=0
    while [ "$i" -lt 45 ]; do
        if BOOTSTRAP_CHECK_DB=1 php docker/bootstrap-env.php --check >/dev/null 2>&1; then
            echo "Database is ready."
            return 0
        fi
        i=$((i + 1))
        sleep 2
    done

    echo "ERROR: Database not reachable after 90s."
    BOOTSTRAP_CHECK_DB=1 php docker/bootstrap-env.php --check 2>&1 || true
    exit 1
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

prepare_runtime() {
    install_dependencies
    bootstrap_env
    mkdir -p var/cache var/log
    chown -R www-data:www-data var 2>/dev/null || true
}

prepare_runtime

case "$MODE" in
    php-fpm)
        wait_for_database
        run_migrations
        exec php-fpm -F
        ;;
    web)
        configure_nginx
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
