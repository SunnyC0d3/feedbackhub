#!/bin/bash
set -e

# Cache config, routes, and views for production.
# Skipped in local dev where the source changes frequently.
if [ "$APP_ENV" = "production" ]; then
    echo "[entrypoint] Caching config, routes, and views..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
