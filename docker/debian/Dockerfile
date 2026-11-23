# The suite on Debian with the lowest PHP. A Dockerfile, so Dependabot updates the digests.
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer

FROM php:8.4.27-cli@sha256:d63e8b80cd4c6cfd01cfa7c910522e299cdc7ca1a2b6d6563db48df3c17e6c55

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apt-get -qq update \
    && apt-get -qq install -y --no-install-recommends unzip libzip-dev > /dev/null \
    && docker-php-ext-install zip pcntl > /dev/null \
    && rm -rf /var/lib/apt/lists/*
