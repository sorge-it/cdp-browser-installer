# A minimal Debian without the libraries of the pair, so that `deps` proves its list. Composer
# only; the job installs PHP from the system. A Dockerfile, so Dependabot updates the digests.
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer

FROM debian:13@sha256:9c6e381421d0f48d1b8133e573f33c185ee16cbf505eda9c4a8a4791b25ea8a6

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
