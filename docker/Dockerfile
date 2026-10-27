FROM php:7.3-cli

# php:7.3 - это Debian buster (EOL): репозитории переехали в archive.debian.org
RUN sed -i 's/deb.debian.org/archive.debian.org/g; s|security.debian.org|archive.debian.org|g; /buster-updates/d' /etc/apt/sources.list \
    && apt-get update && apt-get install -y --no-install-recommends \
       git unzip libzip-dev default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql bcmath zip pcntl sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Xdebug: 3.1.x - последняя ветка с поддержкой PHP 7.3
RUN pecl install xdebug-3.1.6 && docker-php-ext-enable xdebug
COPY xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

WORKDIR /var/www/app
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
