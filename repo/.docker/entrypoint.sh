#!/usr/bin/env bash
set -e

# ─── Wait for the database to be ready ───────────────────────────────────────
DB_CONN="${DB_CONNECTION:-mysql}"
DB_H="${DB_HOST:-127.0.0.1}"
DB_P="${DB_PORT:-3306}"
DB_U="${DB_USERNAME:-vetops}"
MAX_TRIES=30
TRIES=0

if [ "$DB_CONN" = "mysql" ] || [ "$DB_CONN" = "mariadb" ]; then
    echo "[entrypoint] Waiting for MySQL at ${DB_H}:${DB_P}..."
    until mysqladmin ping -h "${DB_H}" -P "${DB_P}" -u "${DB_U}" --silent 2>/dev/null; do
        TRIES=$((TRIES + 1))
        if [ "$TRIES" -ge "$MAX_TRIES" ]; then
            echo "[entrypoint] ERROR: MySQL did not become ready after ${MAX_TRIES} attempts. Aborting." >&2
            exit 1
        fi
        echo "[entrypoint] MySQL not ready yet (attempt ${TRIES}/${MAX_TRIES}) — retrying in 2s..."
        sleep 2
    done
    echo "[entrypoint] MySQL is ready."
elif [ "$DB_CONN" = "pgsql" ]; then
    echo "[entrypoint] Waiting for PostgreSQL at ${DB_H}:${DB_P}..."
    until pg_isready -h "${DB_H}" -p "${DB_P}" -U "${DB_U}" -q; do
        TRIES=$((TRIES + 1))
        if [ "$TRIES" -ge "$MAX_TRIES" ]; then
            echo "[entrypoint] ERROR: PostgreSQL did not become ready after ${MAX_TRIES} attempts. Aborting." >&2
            exit 1
        fi
        echo "[entrypoint] PostgreSQL not ready yet (attempt ${TRIES}/${MAX_TRIES}) — retrying in 2s..."
        sleep 2
    done
    echo "[entrypoint] PostgreSQL is ready."
fi

# ─── Worker mode — queue workers and schedulers pass their command as args ────
if [ $# -gt 0 ]; then
    echo "[entrypoint] Worker mode — executing: $*"
    exec "$@"
fi

# ─── App server mode — run one-time bootstrap then start Supervisor ───────────
if [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] APP_KEY is empty — generating a fresh application key..."
    php artisan key:generate --force --no-interaction
fi

echo "[entrypoint] Clearing config cache..."
php artisan config:clear

echo "[entrypoint] Running database migrations..."
php artisan migrate --force

echo "[entrypoint] Seeding default accounts (skip if already seeded)..."
php artisan db:seed --force 2>/dev/null || true

echo "[entrypoint] Linking public storage..."
php artisan storage:link --force 2>/dev/null || true

# Ensure correct permissions on writable directories
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Ensure supervisor log directory exists
mkdir -p /var/log/supervisor

echo "[entrypoint] Starting Supervisor (nginx + php-fpm)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
