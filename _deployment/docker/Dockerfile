# syntax=docker/dockerfile:1

FROM php:8.1-cli AS app
WORKDIR /var/www/html

# Install system deps and PHP extensions
RUN apt-get update && apt-get install -y git unzip libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-install mbstring xml \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy app
COPY . /var/www/html

# Install PHP dependencies (no dev) at build-time to include WS libs
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

# Ensure storage permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Entrypoint will run key:generate, migrate, etc.
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
