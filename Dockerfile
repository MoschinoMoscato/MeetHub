@'
FROM dunglas/frankenphp:php8.3

RUN install-php-extensions mongodb

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.* ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

COPY . .

EXPOSE 8080

CMD ["frankenphp", "php-server", "--listen", ":8080", "--root", "/app"]
'@ | Set-Content -Encoding UTF8 Dockerfile