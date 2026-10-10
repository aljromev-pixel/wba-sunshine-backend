FROM php:8.4-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpq-dev libonig-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql mbstring intl opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

COPY apache-render.conf /etc/apache2/sites-available/000-default.conf
COPY php-production.ini /usr/local/etc/php/conf.d/production.ini
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i 's/\r$//' start-render.sh \
    && chmod +x start-render.sh

EXPOSE 10000
CMD ["/bin/sh", "/var/www/html/start-render.sh"]
