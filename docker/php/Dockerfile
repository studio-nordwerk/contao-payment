FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install -j$(nproc) intl pdo_mysql gd zip opcache && \
    rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN { echo 'memory_limit=512M'; echo 'date.timezone=UTC'; } > /usr/local/etc/php/conf.d/payment.ini
