# syntax=docker/dockerfile:1
FROM php:8.4-apache AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libicu-dev libonig-dev libpq-dev libsqlite3-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath intl mbstring opcache pcntl pdo_mysql pdo_pgsql pdo_sqlite zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/stockroom.ini
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf

FROM runtime AS build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader
COPY . .
RUN mkdir -p bootstrap/cache storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && php artisan package:discover --no-interaction

FROM runtime AS production
COPY --from=build /var/www/html /var/www/html
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x deploy/entrypoint.sh

ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
EXPOSE 8080
ENTRYPOINT ["/var/www/html/deploy/entrypoint.sh"]
CMD ["apache2-foreground"]
