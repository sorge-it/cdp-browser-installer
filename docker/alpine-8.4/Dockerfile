# The suite on musl with the lowest PHP: unzip for Info-ZIP, zip for the fixtures of the tests,
# pcntl for the tests of a signal. A Dockerfile, so Dependabot updates the digests.
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer

FROM php:8.4.27-cli-alpine@sha256:d8784155c4d37986be9ddd7a8950df7e78998cc48e35472cd69e2b9371424a65

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache unzip libzip-dev $PHPIZE_DEPS linux-headers > /dev/null \
    && docker-php-ext-install zip pcntl > /dev/null
