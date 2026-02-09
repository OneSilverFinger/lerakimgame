FROM php:8.4-fpm-alpine

RUN apk add --no-cache git curl zip unzip oniguruma-dev icu-dev libxml2-dev libzip-dev && \
    docker-php-ext-install pdo pdo_mysql intl xml zip mbstring

WORKDIR /var/www/html

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts

COPY . .
COPY .env .env

RUN composer install --no-dev --prefer-dist --no-interaction && \
    php artisan key:generate --ansi && \
    chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
