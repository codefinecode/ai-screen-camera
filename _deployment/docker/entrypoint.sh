#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

if [ ! -f ".env" ] && [ -f ".env.example" ]; then
    cp .env.example .env
fi

php artisan key:generate --force || true
php artisan config:cache || true
php artisan route:cache || true
php artisan migrate --force || true

exec "$@"
