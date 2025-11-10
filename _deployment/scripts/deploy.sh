#!/bin/bash
set -euo pipefail

cd /var/www/html/AIScreen-AICamera/intermediate-service

echo "Installing dependencies..."
composer install --no-dev --optimize-autoloader

echo "Setting up environment..."
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate
fi

echo "Caching config and routes..."
php artisan config:cache
php artisan route:cache

echo "Running migrations..."
php artisan migrate --force

echo "Setting permissions..."
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "Deploy complete!"

