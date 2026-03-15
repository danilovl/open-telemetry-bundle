FROM php:8.5-alpine

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && apk add --no-cache $PHPIZE_DEPS \
    && pecl install opentelemetry redis \
    && docker-php-ext-enable opentelemetry redis \
    && apk del $PHPIZE_DEPS

WORKDIR /var/www/html/app

CMD tail -f /dev/null
