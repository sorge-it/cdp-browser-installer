# The suite on musl with the lowest PHP: unzip for Info-ZIP, zip for the fixtures of the tests,
# pcntl for the tests of a signal. A Dockerfile, so Dependabot updates the digests.
FROM composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 AS composer

FROM php:8.4.27-cli-alpine@sha256:d8784155c4d37986be9ddd7a8950df7e78998cc48e35472cd69e2b9371424a65

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache unzip libzip-dev $PHPIZE_DEPS linux-headers > /dev/null \
    && docker-php-ext-install zip pcntl > /dev/null
