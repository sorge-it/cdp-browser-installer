# The suite on musl with the newest PHP, and the container of the workflow works-with. sockets for
# chrome-php/chrome there, the rest as in alpine-8.4. A Dockerfile, so Dependabot updates the digests.
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer

FROM php:8.5.12-cli-alpine@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache unzip libzip-dev $PHPIZE_DEPS linux-headers > /dev/null \
    && docker-php-ext-install zip pcntl sockets > /dev/null
