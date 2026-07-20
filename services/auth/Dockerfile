# =============================================================================
# SaintAugustin Auth Service – Dockerfile
# =============================================================================

# ---------- Stage 1: composer dependencies (cacheable) ----------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./

# Create storage directories for Laravel to avoid composer exit code 1 errors when running artisan commands in the next stage.
RUN mkdir -p storage/framework/cache/data \
    && mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/views \
    && mkdir -p storage/logs \
    && mkdir -p bootstrap/cache

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

# ---------- Stage 2: runtime ----------
FROM php:8.3-apache AS runtime

ENV COMPOSER_ALLOW_SUPERUSER=1

# --- System + PHP extension build deps ---
# pdo_pgsql -> PostgreSQL 16 (sa_auth)
# redis (pecl) -> Sanctum sessions / cache
# intl, mbstring, bcmath, zip, opcache, pcntl -> Laravel defaults + queue workers
RUN apt-get update
RUN apt-get install -y \
        unzip \
        curl \
        ca-certificates \
        libpq-dev \
        libzip-dev \
        libonig-dev \
        libicu-dev \
        libxml2-dev
RUN docker-php-ext-configure intl
RUN docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        mbstring \
        bcmath \
        zip \
        intl \
        opcache \
        pcntl

RUN pecl install redis \
    && docker-php-ext-enable redis

# --- Apache config: rewrite + DocumentRoot -> /public ---
RUN a2enmod rewrite headers

# --- PHP production ini + opcache tuning ---
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'realpath_cache_size=4096K'; \
        echo 'realpath_cache_ttl=600'; \
    } > "$PHP_INI_DIR/conf.d/zz-opcache.ini"

WORKDIR /var/www/html

# --- App: vendor first (cached), then source, then optimized autoload ---
COPY --from=vendor /app/vendor ./vendor
COPY --from=vendor /usr/bin/composer /usr/bin/composer
COPY . .
COPY ./apache2.conf /etc/apache2/apache2.conf
COPY ./php.ini /usr/local/etc/php/php.ini

RUN mkdir -p storage/framework/cache/data \
    && mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/views \
    && mkdir -p storage/logs \
    && mkdir -p bootstrap/cache

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Laravel's built-in health endpoint (Laravel 11+; available in 12)
# HEALTHCHECK --interval=30s --timeout=3s --start-period=15s --retries=3 \
#   CMD curl -fsS http://127.0.0.1/up || exit 1

EXPOSE 80

# Inherits CMD ["apache2-foreground"] from base image.
