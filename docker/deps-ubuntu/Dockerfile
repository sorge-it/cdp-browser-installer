# A minimal Ubuntu without the libraries of the pair, so that `deps` proves its list. 26.04, as the
# PHP of 24.04 is 8.3. Composer only. A Dockerfile, so Dependabot updates the digests.
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer

FROM ubuntu:26.04@sha256:6a06111c3b0a8600feadb270aec771dc239bf407b25625f93f44ab00e11f4d3c

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
