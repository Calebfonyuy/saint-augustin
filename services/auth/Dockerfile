# =============================================================================
# SaintAugustin Auth Service – Dockerfile
# Laravel 12 + Octane (FrankenPHP) on PHP 8.3 Alpine
#
# Dev mode: mounts source via volume, Octane watches for changes.
# Prod mode: COPY source, no volumes, optimized autoloader.
#
# Ref: https://laravel.com/docs/12.x/octane
# Ref: https://hub.docker.com/_/php (official PHP images)
# =============================================================================

FROM php:8.3-cli-alpine AS base

# System dependencies required by PHP extensions and Composer
RUN apk add --no-cache \
    curl \
    libpq-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    linux-headers \
    $PHPIZE_DEPS

# PHP extensions required by Laravel + PostgreSQL
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    zip \
    intl \
    opcache \
    pcntl \
    sockets

# Redis extension via PECL
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy composer files first for layer caching
COPY composer.json composer.lock* ./

# Install dependencies (no dev in production, with dev in development)
ARG APP_ENV=local
RUN if [ "$APP_ENV" = "production" ]; then \
        composer install --no-dev --optimize-autoloader --no-interaction; \
    else \
        composer install --no-interaction; \
    fi

# Copy application source
COPY . .

# Ensure storage and cache directories exist and are writable
RUN mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8000

# Development: Octane with --watch for hot reload
# Override in production with: php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000", "--watch"]
