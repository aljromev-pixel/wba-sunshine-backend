#!/bin/sh
set -eu

: "${APP_KEY:?Set a stable Laravel APP_KEY in Render.}"
: "${DB_HOST:?Set the Supabase Session pooler hostname.}"
: "${DB_PASSWORD:?Set the Supabase database password.}"
: "${CORS_ALLOWED_ORIGINS:?Set your exact Vercel frontend origin.}"
if [ "${APP_ENV:-}" != production ] || [ "${APP_DEBUG:-true}" != false ] || [ "${DB_CONNECTION:-}" != pgsql ]; then
    echo 'Render requires APP_ENV=production, APP_DEBUG=false and DB_CONNECTION=pgsql.' >&2
    exit 1
fi
task_port=${PORT:-10000}
case "$task_port" in ''|*[!0-9]*) echo 'PORT must be numeric.' >&2; exit 1;; esac
if [ "$task_port" -lt 1 ] || [ "$task_port" -gt 65535 ]; then exit 1; fi
printf 'Listen %s\n' "$task_port" > /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:10000>/<VirtualHost *:$task_port>/" /etc/apache2/sites-available/000-default.conf

php artisan config:clear --no-interaction
# Free Render has no pre-deploy command: run pending migrations on the single instance.
php artisan migrate --force --no-interaction
if [ "${RUN_BOOTSTRAP_ADMIN:-false}" = true ]; then
    php artisan app:bootstrap-admin --no-interaction
fi
# Exclude bootstrap credentials from cached configuration and the web process environment.
unset BOOTSTRAP_ADMIN_PASSWORD BOOTSTRAP_ADMIN_EMAIL BOOTSTRAP_ADMIN_NAME RUN_BOOTSTRAP_ADMIN
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
chown -R www-data:www-data storage bootstrap/cache
exec apache2-foreground
