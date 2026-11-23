# The suite on Debian with the lowest PHP. A Dockerfile, so Dependabot updates the digests.
FROM composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 AS composer

FROM php:8.4.27-cli@sha256:9cf851bc2c1b37c3e59dfd677a3c3e56d485af5a47c93f629424bb039eafcb69

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apt-get -qq update \
    && apt-get -qq install -y --no-install-recommends unzip libzip-dev > /dev/null \
    && docker-php-ext-install zip pcntl > /dev/null \
    && rm -rf /var/lib/apt/lists/*
