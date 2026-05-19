#!/bin/sh
set -e

cd /var/www

MODE="${1:-web}"

ensure_env_file() {
    if [ -f .env ]; then
        return 0
    fi

    if [ -f .env.docker ]; then
        cp .env.docker .env
        return 0
    fi

    cat > .env <<EOF
APP_ENV=${APP_ENV:-prod}
APP_SECRET=${APP_SECRET:-change_me_in_production}
APP_SHARE_DIR=var/share
DEFAULT_URI=${DEFAULT_URI:-http://localhost}
DATABASE_URL=${DATABASE_URL:-}
MESSENGER_TRANSPORT_DSN=${MESSENGER_TRANSPORT_DSN:-doctrine://default?auto_setup=0}
MAILER_DSN=${MAILER_DSN:-null://null}
EOF
}

require_runtime_config() {
    if [ -z "$APP_SECRET" ] || [ "$APP_SECRET" = "change_me_in_production" ]; then
        echo "ERROR: Set APP_SECRET in Railway (or docker-compose environment)."
        exit 1
    fi

    if [ -z "$DATABASE_URL" ]; then
        echo "ERROR: Set DATABASE_URL in Railway (MySQL plugin) or docker-compose environment."
        exit 1
    fi
}

install_dependencies() {
    if [ ! -f vendor/autoload.php ]; then
        composer install --no-interaction --no-scripts
    fi
}

wait_for_database() {
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

    echo "ERROR: Database not reachable. Check DATABASE_URL (host, user, password, database name)."
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

ensure_env_file
require_runtime_config
install_dependencies

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
