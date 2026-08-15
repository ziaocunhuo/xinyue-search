#!/bin/bash
set -e

echo "=== Xinyue Search PHP Container Starting ==="

cd /var/www/html

mkdir -p runtime/session runtime/log runtime/cache
chmod -R 777 runtime

if [ ! -f ".env" ]; then
    cat > .env <<EOF
[DATABASE]
TYPE = mysql
HOSTNAME = ${DB_HOST:-mariadb}
DATABASE = ${DB_NAME:-xinyue_search}
USERNAME = ${DB_USER:-xinyue}
PASSWORD = ${DB_PASS:-xinyue_pass_2026}
HOSTPORT = ${DB_PORT:-3306}
CHARSET = utf8mb4
PREFIX = ${DB_PREFIX:-qf_}
DEBUG = ${APP_DEBUG:-false}
EOF
    echo "[entrypoint] .env created"
else
    echo "[entrypoint] .env already exists, skipping"
fi

echo "[entrypoint] Waiting for database..."
until php -r "
try {
    \$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASS'));
    echo 'DB OK';
    exit(0);
} catch (Exception \$e) {
    echo 'DB not ready: ' . \$e->getMessage();
    exit(1);
}
" 2>&1; do
    echo "[entrypoint] DB not ready, retrying in 3s..."
    sleep 3
done
echo "[entrypoint] Database is ready"

if [ -f "public/install/data.sql" ]; then
    echo "[entrypoint] Database init SQL exists, importing..."
    mysql -h ${DB_HOST:-mariadb} -P ${DB_PORT:-3306} -u ${DB_USER:-xinyue} -p${DB_PASS:-xinyue_pass_2026} ${DB_NAME:-xinyue_search} < public/install/data.sql 2>&1 || true
    echo "[entrypoint] DB init attempted"
fi

echo "[entrypoint] Starting PHP-FPM..."
exec "$@"
