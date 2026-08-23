FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

FROM php:8.3-cli-alpine
RUN apk add --no-cache libpng-dev libjpeg-turbo-dev freetype-dev icu-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql gd intl mbstring bcmath opcache
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8000
COPY docker-entrypoint.sh /usr/local/bin/smart-bodim-entrypoint
RUN chmod +x /usr/local/bin/smart-bodim-entrypoint
ENTRYPOINT ["smart-bodim-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
