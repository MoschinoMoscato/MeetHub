FROM dunglas/frankenphp:php8.3

RUN install-php-extensions mongodb

WORKDIR /app

COPY . /app

EXPOSE 8080

CMD ["frankenphp", "php-server", "--listen", ":8080", "--root", "/app"]