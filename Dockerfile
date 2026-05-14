FROM dunglas/frankenphp:php8.3

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
 && apt-get install -y git unzip ca-certificates openssl \
 && update-ca-certificates \
 && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions mongodb zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer update --no-dev --prefer-dist --no-interaction --optimize-autoloader

COPY . .

EXPOSE 8080

CMD ["frankenphp", "php-server", "--listen", ":8080", "--root", "/app"]