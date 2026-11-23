# The suite on musl with the newest PHP, and the container of the workflow works-with. sockets for
# chrome-php/chrome there, the rest as in alpine-8.4. A Dockerfile, so Dependabot updates the digests.
FROM composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 AS composer

FROM php:8.5.12-cli-alpine@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache unzip libzip-dev $PHPIZE_DEPS linux-headers > /dev/null \
    && docker-php-ext-install zip pcntl sockets > /dev/null
