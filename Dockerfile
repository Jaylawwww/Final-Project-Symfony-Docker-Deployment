FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev zip curl nginx \
    && docker-php-ext-install pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .
COPY .env.docker .env

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=prod \
    APP_DEBUG=0 \
    PORT=8080

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY nginx-main.conf /etc/nginx/nginx.conf
COPY docker/nginx-site.conf.template /etc/nginx/templates/default.conf.template
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY nginx.conf /etc/nginx/conf.d/default.conf

RUN mkdir -p var/cache var/log /etc/nginx/templates \
    && chown -R www-data:www-data var public

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["web"]
