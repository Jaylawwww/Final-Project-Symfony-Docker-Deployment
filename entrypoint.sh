#!/bin/sh
set -e

cd /var/www

MODE="${1:-web}"
PORT="${PORT:-8080}"
export PORT

log() {
    echo "[entrypoint] $*"
}

install_dependencies() {
    if [ ! -f vendor/autoload.php ]; then
        log "Installing Composer dependencies..."
        composer install --no-interaction --no-scripts
    fi
}

bootstrap_env() {
    log "Loading environment..."
    php docker/bootstrap-env.php
}

configure_nginx() {
    log "Configuring nginx on port ${PORT}..."
    sed "s/__PORT__/${PORT}/g" /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf
    nginx -t
}

wait_for_database() {
    log "Waiting for database..."
    i=0
    while [ "$i" -lt 45 ]; do
        if BOOTSTRAP_CHECK_DB=1 php docker/bootstrap-env.php --check >/dev/null 2>&1; then
            log "Database is ready."
            return 0
        fi
        i=$((i + 1))
        sleep 2
    done

    log "ERROR: Database not reachable after 90s."
    BOOTSTRAP_CHECK_DB=1 php docker/bootstrap-env.php --check 2>&1 || true
    exit 1
}

run_migrations() {
    log "Running migrations..."
    if php bin/console doctrine:migrations:migrate --no-interaction --no-ansi; then
        log "Migrations complete."
    else
        log "WARNING: Migrations failed (app may still start)."
    fi
}

prepare_assets() {
    log "Installing importmap and compiling assets for production..."
    php bin/console importmap:install --no-interaction
    php bin/console asset-map:compile --no-interaction
    chown -R www-data:www-data public/assets assets/vendor 2>/dev/null || true
}

warm_symfony_cache() {
    log "Warming Symfony cache..."
    php bin/console cache:clear --env="${APP_ENV:-prod}" --no-warmup
    php bin/console cache:warmup --env="${APP_ENV:-prod}"
    chown -R www-data:www-data var public
}

start_php_fpm() {
    log "Starting PHP-FPM..."
    php-fpm -D
}

start_nginx() {
    log "Starting nginx on 0.0.0.0:${PORT}..."
    exec nginx -g 'daemon off;'
}

prepare_runtime() {
    install_dependencies
    bootstrap_env
    mkdir -p var/cache var/log
    chown -R www-data:www-data var public 2>/dev/null || true
}

log "Mode: ${MODE}, PORT: ${PORT}"
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
        prepare_assets
        warm_symfony_cache
        start_php_fpm
        start_nginx
        ;;
    *)
        exec "$@"
        ;;
esac
