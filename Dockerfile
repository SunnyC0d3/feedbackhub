# ---------------------------------------------------------------
# Stage 1: Composer dependencies
# ---------------------------------------------------------------
FROM composer:2.7 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ---------------------------------------------------------------
# Stage 2: Production image
# ---------------------------------------------------------------
FROM php:8.1-fpm-bookworm AS app

# System dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    libzip-dev \
    libpng-dev \
    libxml2-dev \
    libonig-dev \
    libcurl4-openssl-dev \
    libicu-dev \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    xml \
    zip \
    bcmath \
    opcache \
    intl \
    pcntl

# Redis extension via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# PHP config
COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini

# Supervisord config (runs PHP-FPM for the web container)
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Copy vendor from stage 1, then app source
COPY --from=vendor /app/vendor ./vendor
COPY . .

# Storage & cache directories need www-data write access
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
