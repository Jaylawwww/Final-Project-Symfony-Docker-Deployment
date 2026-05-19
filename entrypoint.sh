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

# Railway MySQL plugin exposes MYSQL_* / MYSQL_URL; Symfony expects DATABASE_URL.
build_database_url() {
    if [ -n "$DATABASE_URL" ]; then
        :
    elif [ -n "$MYSQL_URL" ]; then
        DATABASE_URL="$MYSQL_URL"
    elif [ -n "$MYSQL_PUBLIC_URL" ]; then
        DATABASE_URL="$MYSQL_PUBLIC_URL"
    elif [ -n "$MYSQLHOST" ] && [ -n "$MYSQLUSER" ] && [ -n "$MYSQLPASSWORD" ] && [ -n "$MYSQLDATABASE" ]; then
        DATABASE_URL=$(php -r '
            $user = rawurlencode(getenv("MYSQLUSER"));
            $pass = rawurlencode(getenv("MYSQLPASSWORD"));
            $host = getenv("MYSQLHOST");
            $port = getenv("MYSQLPORT") ?: "3306";
            $db = getenv("MYSQLDATABASE");
            echo "mysql://{$user}:{$pass}@{$host}:{$port}/{$db}";
        ')
    fi

    if [ -z "$DATABASE_URL" ]; then
        return 1
    fi

    export DATABASE_URL

    case "$DATABASE_URL" in
        *serverVersion=*) ;;
        *\?*) DATABASE_URL="${DATABASE_URL}&serverVersion=8.0&charset=utf8mb4" ;;
        *) DATABASE_URL="${DATABASE_URL}?serverVersion=8.0&charset=utf8mb4" ;;
    esac

    export DATABASE_URL
}

sync_database_url_to_env_file() {
    if [ ! -f .env ] || [ -z "$DATABASE_URL" ]; then
        return 0
    fi

    php -r '
        $url = getenv("DATABASE_URL");
        $path = ".env";
        $line = "DATABASE_URL=" . json_encode($url, JSON_UNESCAPED_SLASHES);
        $contents = file_get_contents($path);
        if (preg_match("/^DATABASE_URL=/m", $contents)) {
            $contents = preg_replace("/^DATABASE_URL=.*/m", $line, $contents);
        } else {
            $contents .= PHP_EOL . $line . PHP_EOL;
        }
        file_put_contents($path, $contents);
    '
}

print_database_target() {
    php -r '
        $url = getenv("DATABASE_URL");
        if (!$url) {
            echo "DATABASE_URL is not set.";
            exit(0);
        }
        $parts = parse_url($url);
        $host = $parts["host"] ?? "unknown";
        $port = $parts["port"] ?? "3306";
        $db = ltrim($parts["path"] ?? "", "/");
        $user = $parts["user"] ?? "unknown";
        echo "Connecting to mysql://{$user}@{$host}:{$port}/{$db}";
    '
}

require_runtime_config() {
    if [ -z "$APP_SECRET" ] || [ "$APP_SECRET" = "change_me_in_production" ]; then
        echo "ERROR: Set APP_SECRET in Railway (or docker-compose environment)."
        exit 1
    fi

    if ! build_database_url; then
        echo "ERROR: No database URL found."
        echo "On Railway: open your MySQL service → Variables → add a reference on the app service:"
        echo "  DATABASE_URL = \${{MySQL.MYSQL_URL}}"
        echo "Or set MYSQL_URL / MYSQLHOST + MYSQLUSER + MYSQLPASSWORD + MYSQLDATABASE."
        exit 1
    fi

    sync_database_url_to_env_file

    case "$DATABASE_URL" in
        *@db:*|*@db/*)
            echo "ERROR: DATABASE_URL uses host 'db' — that only works in docker-compose."
            echo "Replace it with the MySQL URL from your Railway MySQL service variables."
            exit 1
            ;;
    esac

    echo "Database target: $(print_database_target)"
}

install_dependencies() {
    if [ ! -f vendor/autoload.php ]; then
        composer install --no-interaction --no-scripts
    fi
}

wait_for_database() {
    echo "Waiting for database..."
    i=0
    while [ "$i" -lt 45 ]; do
        if php bin/console doctrine:query:sql "SELECT 1" >/dev/null 2>&1; then
            echo "Database is ready."
            return 0
        fi
        i=$((i + 1))
        sleep 2
    done

    echo "ERROR: Database not reachable after 90s."
    echo "Last error from Symfony:"
    php bin/console doctrine:query:sql "SELECT 1" 2>&1 || true
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
