# A minimal Debian without the libraries of the pair, so that `deps` proves its list. Composer
# only; the job installs PHP from the system. A Dockerfile, so Dependabot updates the digests.
FROM composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 AS composer

FROM debian:13@sha256:2dc419c6d7fe3dad0a2f9fd469c34cc90b0f9ba642d8fde837fe3d7d34a0e7f1

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
